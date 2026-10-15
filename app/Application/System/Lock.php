<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\System;

use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\LockedException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\RefusedException;

/**
 * One flock per restic repository: a parallel run fails at once. The file records the holder's PID,
 * start time and operation; it is truncated, not removed, on release (removing it would let two runs
 * lock two different inodes). Content left behind means the previous holder died without releasing:
 * the kernel already dropped its flock, so the lock is taken and the stale holder is reported.
 *
 * Contract: cron, systemd and login shells must see the same lock file for the same repository,
 * otherwise two runs started from different contexts would not see each other. Root always lands
 * on /run/arkhive (created 0700 root:root; the systemd units name it too, with RuntimeDirectory=),
 * a private directory no other user can plant a file in. Other users share /run/lock, a
 * world-writable sticky directory: there a file another user created first is refused as theirs
 * (exit 77), and the file opened is checked to be the one at the path, never a symbolic link.
 */
final class Lock
{
    /** root's lock directory: private, so no other user can block or redirect root's lock */
    public const PRIVATE_DIRECTORY = '/run/arkhive';

    /** @var resource|null */
    private $handle = null;

    /**
     * @param string|null $privateDirectory created (0700) and verified before the lock is opened
     */
    public function __construct(
        private readonly string $path,
        private readonly Clock $clock,
        private readonly ?string $privateDirectory = null,
    ) {
    }

    /**
     * The lock file for a repository: in ARKHIVE_LOCK_DIR when set, as root in /run/arkhive, else in
     * the first writable of /run/lock, $XDG_RUNTIME_DIR, /run/user/<uid>, the temp dir.
     *
     * @param array<string, string> $env
     */
    public static function forRepository(string $repository, array $env, Clock $clock, int $uid, string $runLock = '/run/lock', string $private = self::PRIVATE_DIRECTORY): self
    {
        $directory = self::directory($env, $uid, $runLock, $private);

        return new self($directory . '/arkhive-' . substr(hash('sha256', $repository), 0, 16) . '.lock', $clock, $directory === $private ? $private : null);
    }

    /**
     * @param array<string, string> $env
     */
    public static function directory(array $env, int $uid, string $runLock = '/run/lock', string $private = self::PRIVATE_DIRECTORY): string
    {
        $override = $env['ARKHIVE_LOCK_DIR'] ?? '';
        if (self::usable($override)) {
            return rtrim($override, '/') ?: '/';
        }
        if ($uid === 0) {
            // created when the lock is taken: reading a holder (doctor, status) changes nothing
            return $private;
        }
        foreach ([$runLock, $env['XDG_RUNTIME_DIR'] ?? '', "/run/user/{$uid}"] as $candidate) {
            if (self::usable($candidate)) {
                return rtrim($candidate, '/') ?: '/';
            }
        }

        return rtrim(sys_get_temp_dir(), '/');
    }

    private static function usable(string $directory): bool
    {
        return str_starts_with($directory, '/') && is_dir($directory) && is_writable($directory);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Takes the lock or fails immediately.
     *
     * @return string|null a warning about a stale holder, or null
     *
     * @throws ArkhiveException when another run holds the lock
     */
    public function acquire(string $operation): ?string
    {
        if ($this->handle !== null) {
            return null;
        }
        if ($this->privateDirectory !== null) {
            self::preparePrivate($this->privateDirectory);
        }
        // a shared lock directory is world-writable: never follow a planted symlink or use another user's file
        $before = $this->inspect();
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            // fs.protected_regular refuses even root an O_CREAT open of another user's file in a sticky directory
            $this->inspect();

            throw new RefusedException("Cannot open the lock file {$this->path}", hint: 'Check that its directory is writable, or set ARKHIVE_LOCK_DIR');
        }
        $opened = fstat($handle);
        $after = $this->inspect($handle);
        if ($opened === false || $after === null || $opened['ino'] !== $after['ino'] || $opened['dev'] !== $after['dev'] || ($before !== null && $before['ino'] !== $opened['ino'])) {
            fclose($handle);

            throw new PermissionException("Refusing the lock file {$this->path}: it was replaced while it was being opened", hint: 'Another user may be tampering with the lock directory: set ARKHIVE_LOCK_DIR to a private directory');
        }
        @chmod($this->path, 0600);
        clearstatcache(true, $this->path);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $holder = self::describe(self::read($handle));
            fclose($handle);

            throw new LockedException("Another ArkHive run holds the lock for this repository{$holder}: {$this->path}");
        }

        $previous = self::read($handle);
        $stale = $previous === [] ? null : 'A previous ArkHive run' . self::describe($previous) . ' ended without releasing its lock (killed or crashed); taking the lock over';

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf("pid=%d\nstarted=%s\noperation=%s\n", getmypid(), $this->clock->now()->format(DATE_ATOM), $operation));
        fflush($handle);
        $this->handle = $handle;

        return $stale;
    }

    /**
     * The lock file as it is now (lstat, never following a link), or null when there is none.
     * A symbolic link or a file of another user is refused (exit 77).
     *
     * @param resource|null $handle closed when the file is refused
     *
     * @return array{ino: int, dev: int}|null
     */
    private function inspect($handle = null): ?array
    {
        clearstatcache(true, $this->path);
        $stat = @lstat($this->path);
        if ($stat === false) {
            return null;
        }
        $refuse = static function (string $why, string $hint) use ($handle): PermissionException {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return new PermissionException($why, hint: $hint);
        };
        if (($stat['mode'] & 0170000) === 0120000) {
            throw $refuse("Refusing the lock file {$this->path}: it is a symbolic link", 'Remove it, or set ARKHIVE_LOCK_DIR to a private directory');
        }
        $uid = posix_geteuid();
        if ($stat['uid'] !== $uid) {
            throw $refuse(
                "Refusing the lock file {$this->path}: it belongs to uid {$stat['uid']}, not to the running user (uid {$uid}), in a shared directory",
                'Another user created it first, which blocks ArkHive: remove it, or set ARKHIVE_LOCK_DIR to a private directory',
            );
        }

        return ['ino' => $stat['ino'], 'dev' => $stat['dev']];
    }

    /**
     * Creates root's lock directory (0700) or checks the existing one: a real directory of the
     * running user that nobody else can write to.
     */
    private static function preparePrivate(string $directory): void
    {
        if (!@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RefusedException("Cannot create the lock directory {$directory}", hint: 'Set ARKHIVE_LOCK_DIR to a directory only this user can write');
        }
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        $uid = posix_geteuid();
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new PermissionException("Refusing the lock directory {$directory}: it is not a directory (a symbolic link?)", hint: 'Remove it, or set ARKHIVE_LOCK_DIR to a directory only this user can write');
        }
        if ($stat['uid'] !== $uid) {
            throw new PermissionException("Refusing the lock directory {$directory}: it belongs to uid {$stat['uid']}, not to the running user (uid {$uid})", hint: 'Remove it, or set ARKHIVE_LOCK_DIR to a directory only this user can write');
        }
        if (($stat['mode'] & 0077) !== 0 && !@chmod($directory, 0700)) {
            throw new PermissionException(sprintf('Refusing the lock directory %s: it is open to other users (mode %04o)', $directory, $stat['mode'] & 07777), hint: "Run: chmod 700 {$directory}");
        }
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        ftruncate($this->handle, 0);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function held(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Holder recorded in a lock file, without taking the lock (for doctor and status).
     *
     * @return array<string, string>
     */
    public function holder(): array
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            return [];
        }
        $values = self::read($handle);
        fclose($handle);

        return $values;
    }

    /**
     * @param resource $handle
     *
     * @return array<string, string>
     */
    private static function read($handle): array
    {
        rewind($handle);
        $content = stream_get_contents($handle);
        $values = [];
        foreach (explode("\n", is_string($content) ? $content : '') as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2 && in_array($parts[0], ['pid', 'started', 'operation'], true)) {
                $values[$parts[0]] = $parts[1];
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private static function describe(array $values): string
    {
        $details = [];
        if (isset($values['pid'])) {
            $details[] = 'PID ' . $values['pid'];
        }
        if (isset($values['operation'])) {
            $details[] = $values['operation'];
        }
        if (isset($values['started'])) {
            $details[] = 'started ' . $values['started'];
        }

        return $details === [] ? '' : ' (' . implode(', ', $details) . ')';
    }
}
