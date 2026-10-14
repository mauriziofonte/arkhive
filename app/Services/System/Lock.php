<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\System;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Support\Clock;

/**
 * One flock per restic repository: a parallel run fails at once. The file records the holder's PID,
 * start time and operation; it is truncated, not removed, on release (removing it would let two runs
 * lock two different inodes). Content left behind means the previous holder died without releasing:
 * the kernel already dropped its flock, so the lock is taken and the stale holder is reported.
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $path,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The lock file for a repository. The directory is the same for cron, systemd and login shells
     * whenever possible, otherwise two runs started from different contexts would not see each other:
     * ARKHIVE_LOCK_DIR, then /run/lock when writable, then $XDG_RUNTIME_DIR (or /run/user/<uid>),
     * then the temp dir.
     *
     * @param array<string, string> $env
     */
    public static function forRepository(string $repository, array $env, Clock $clock, int $uid, string $runLock = '/run/lock'): self
    {
        return new self(self::directory($env, $uid, $runLock) . '/arkhive-' . substr(hash('sha256', $repository), 0, 16) . '.lock', $clock);
    }

    /**
     * @param array<string, string> $env
     */
    public static function directory(array $env, int $uid, string $runLock = '/run/lock'): string
    {
        $candidates = [
            $env['ARKHIVE_LOCK_DIR'] ?? '',
            $runLock,
            $env['XDG_RUNTIME_DIR'] ?? '',
            "/run/user/{$uid}",
        ];
        foreach ($candidates as $candidate) {
            if (str_starts_with($candidate, '/') && is_dir($candidate) && is_writable($candidate)) {
                return rtrim($candidate, '/') ?: '/';
            }
        }

        return rtrim(sys_get_temp_dir(), '/');
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
        // lock directories can be world-writable: never follow a planted symlink or use another user's file
        if (is_link($this->path)) {
            throw new PermissionException("Refusing the lock file {$this->path}: it is a symbolic link", hint: 'Remove it, or set ARKHIVE_LOCK_DIR to a private directory');
        }
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RefusedException("Cannot open the lock file {$this->path}", hint: 'Check that its directory is writable, or set ARKHIVE_LOCK_DIR');
        }
        $owner = fstat($handle)['uid'] ?? -1;
        if ($owner !== posix_geteuid()) {
            fclose($handle);

            throw new PermissionException("Refusing the lock file {$this->path}: it belongs to uid {$owner}, not to the running user", hint: 'Remove it, or set ARKHIVE_LOCK_DIR to a private directory');
        }
        @chmod($this->path, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $holder = self::describe(self::read($handle));
            fclose($handle);

            throw new TemporaryException("Another ArkHive run holds the lock for this repository{$holder}: {$this->path}");
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
