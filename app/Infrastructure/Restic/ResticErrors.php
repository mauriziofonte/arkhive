<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\RepairAdvice;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\LockedException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * Turns a failed restic run into one precise error with the fix. restic >= 0.17 has dedicated exit
 * codes (10 no repository, 11 locked, 12 wrong password); older versions are recognised by stderr.
 */
final class ResticErrors
{
    public const NO_REPOSITORY = 10;
    public const LOCKED = 11;
    public const WRONG_PASSWORD = 12;

    /** data that is there but fails verification: a damaged pack file */
    private const DAMAGED_DATA = ['/ciphertext verification failed/', '/\bLoadRaw\(/'];

    /** the text restic prints when it catches a signal and cancels (0.17 exits 1, later 130) */
    private const CAUGHT_SIGNALS = ['hangup' => 1, 'interrupt' => 2, 'terminated' => 15];

    public static function exception(ProcessResult $result, string $what, ResticSettings $settings): ArkhiveException
    {
        // JSON records (--json, restic 0.18+) are read as their messages, one line each
        $stderr = implode("\n", self::lines($result->stderr));
        if ($result->timedOut) {
            return new TemporaryException($result->failure($what), hint: self::reachHint($settings));
        }
        $signal = $result->signal ?? self::caughtSignal($stderr);
        if ($signal !== null) {
            return self::stopped($what, $signal, $result->signal !== null);
        }
        if ($result->exitCode === self::WRONG_PASSWORD || str_contains($stderr, 'wrong password or no key found')) {
            return new PermissionException(
                "Wrong password for the restic repository {$settings->repository} (RESTIC_PASSWORD_FILE {$settings->passwordFile})",
                hint: 'Put the repository password in that file; it is the password given to restic init, kept off the host as well',
            );
        }
        if ($result->exitCode === self::NO_REPOSITORY || str_contains($stderr, 'Is there a repository at the following location?')) {
            return new UnavailableException(
                "There is no restic repository at {$settings->repository}",
                hint: 'Check RESTIC_REPOSITORY, or create the repository with: arkhive init',
            );
        }
        if ($result->exitCode === self::LOCKED || str_contains($stderr, 'repository is already locked')) {
            $holder = preg_match('/already locked (?:exclusively )?by (.+)$/m', $stderr, $m) === 1 ? ' by ' . trim($m[1]) : '';
            $since = preg_match('/lock was created at (.+)$/m', $stderr, $c) === 1 ? ', created at ' . trim($c[1]) : '';

            return new LockedException(
                "The restic repository is locked{$holder}{$since}",
                hint: 'If no other restic process is using the repository the lock is stale; remove it with: RESTIC_PASSWORD_FILE='
                    . escapeshellarg($settings->passwordFile) . ' restic -r ' . escapeshellarg($settings->repository) . ' unlock (ArkHive never does this automatically)',
            );
        }
        if (str_contains($stderr, 'unsupported repository version')) {
            return new UnavailableException(
                "This restic cannot open the repository {$settings->repository}: it uses a newer repository format",
                hint: 'Repository format 2 (compression) needs restic 0.14.0 or newer: install it from your distribution or the static binary from https://github.com/restic/restic/releases (then set RESTIC_BINARY)',
            );
        }
        if (stripos($stderr, 'no space left on device') !== false) {
            return new IoException($result->failure($what), hint: 'The filesystem holding the repository (or the restic cache) is full: free space, or prune old snapshots with arkhive maintain');
        }
        $missing = self::firstMatch($stderr, ResticOutput::MISSING_DATA);
        $damaged = $missing === null ? self::firstMatch($stderr, self::DAMAGED_DATA) : null;
        if ($missing !== null || $damaged !== null) {
            return self::dataError($what, $settings, $result->stderr, $missing, $damaged);
        }
        if (self::looksLikeSsh($stderr)) {
            // a refused key or an unknown host key stays refused: only connection failures are retried
            return self::refusedBySsh($stderr)
                ? new UnavailableException($result->failure($what), self::reachHint($settings))
                : UnavailableException::network($result->failure($what), self::reachHint($settings));
        }

        return new ArkhiveException($result->failure($what));
    }

    /**
     * restic stopped by a signal ArkHive did not send (its own SIGINT/SIGTERM end the run as
     * interrupted before the result gets here): exit 75, not tried again in this run.
     */
    private static function stopped(string $what, int $signal, bool $killed): TemporaryException
    {
        $name = ProcessResult::signalName($signal);
        $message = $killed
            ? sprintf('%s failed: restic was killed by signal %d (%s%s)', $what, $signal, $name, $signal === 9 ? ': out of memory?' : '')
            : sprintf('%s failed: restic was stopped by signal %d (%s) and cancelled the operation', $what, $signal, $name);
        $who = $signal === 9
            ? "the kernel's out-of-memory killer (look for \"Out of memory\" in journalctl -k or dmesg; restic needs memory in proportion to the repository index), an administrator or a service manager"
            : 'an administrator, a service manager or a shutdown';

        return TemporaryException::notRetried($message, "ArkHive did not send it: {$who} did. Run it again once the cause is gone");
    }

    private static function caughtSignal(string $stderr): ?int
    {
        return preg_match('/^signal (hangup|interrupt|terminated) received, cleaning up/m', $stderr, $match) === 1 ? self::CAUGHT_SIGNALS[$match[1]] : null;
    }

    /**
     * stderr as lines of text: a JSON record is replaced by its message (which can span lines),
     * terminal escape sequences are removed, empty lines are dropped.
     *
     * @return list<string>
     */
    private static function lines(string $stderr): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            $line = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line);
            foreach (preg_split('/\R/', ResticJson::message($line) ?? $line) ?: [] as $part) {
                if (trim($part) !== '') {
                    $lines[] = trim($part);
                }
            }
        }

        return $lines;
    }

    /**
     * Exit 65 with the repair commands: a damaged repository is never retried and never repaired here.
     */
    private static function dataError(string $what, ResticSettings $settings, string $stderr, ?string $missing, ?string $damaged): DataException
    {
        $packs = [];
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            foreach (ResticOutput::missingPacks($line) as $id) {
                $packs[$id] = true;
            }
        }
        if ($packs !== []) {
            $ids = array_keys($packs);
            $message = sprintf(
                '%s failed: %d pack file(s) listed in the index are missing from the repository %s (%s%s)',
                $what,
                count($ids),
                $settings->repository,
                implode(', ', array_map(static fn (string $id): string => substr($id, 0, 8), array_slice($ids, 0, 3))),
                count($ids) > 3 ? ', and ' . (count($ids) - 3) . ' more' : '',
            );
        } elseif ($missing !== null) {
            $message = "{$what} failed: data the repository {$settings->repository} should hold is missing (pack files deleted or lost on the server): {$missing}";
        } else {
            $message = "{$what} failed: the repository {$settings->repository} holds damaged data: {$damaged}";
        }

        return new DataException($message, hint: RepairAdvice::hint([], $missing !== null, $settings->commandLine()));
    }

    /**
     * The first line of the decoded stderr matching one of $patterns.
     *
     * @param list<string> $patterns
     */
    private static function firstMatch(string $stderr, array $patterns): ?string
    {
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            foreach ($patterns as $pattern) {
                if ($line !== '' && preg_match($pattern, $line) === 1) {
                    return $line;
                }
            }
        }

        return null;
    }

    /**
     * restic relays ssh's own stderr asynchronously and can exit before it arrives, so its own
     * "unable to start the sftp session" counts as well.
     */
    private static function looksLikeSsh(string $stderr): bool
    {
        foreach (['ssh:', 'subprocess ssh', 'Permission denied (publickey', 'Connection refused', 'Connection timed out', 'Host key verification failed', 'Could not resolve hostname', 'connection lost', 'unable to start the sftp session', 'ssh command exited'] as $needle) {
            if (str_contains($stderr, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function refusedBySsh(string $stderr): bool
    {
        return str_contains($stderr, 'Permission denied (') || str_contains($stderr, 'Host key verification failed');
    }

    private static function reachHint(ResticSettings $settings): string
    {
        if ($settings->sftpCommand !== null) {
            return "Check that the repository server is up and that this command works by hand: {$settings->sftpCommand}";
        }
        if (str_starts_with($settings->repository, 'sftp:')) {
            return 'Check that the SFTP server is up and accepts the SSH key of this user; for a non-standard port or key set RESTIC_SFTP_COMMAND';
        }

        return 'Check that the repository location is reachable';
    }
}
