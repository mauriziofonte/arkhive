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

    public static function exception(ProcessResult $result, string $what, ResticSettings $settings): ArkhiveException
    {
        $stderr = $result->stderr;
        if ($result->timedOut) {
            return new TemporaryException($result->failure($what), hint: self::reachHint($settings));
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
            return self::dataError($what, $settings, $stderr, $missing, $damaged);
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
     * The first stderr line (a JSON record's message) matching one of $patterns.
     *
     * @param list<string> $patterns
     */
    private static function firstMatch(string $stderr, array $patterns): ?string
    {
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            $text = ResticJson::message($line) ?? trim($line);
            foreach ($patterns as $pattern) {
                if ($text !== '' && preg_match($pattern, $text) === 1) {
                    return $text;
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
