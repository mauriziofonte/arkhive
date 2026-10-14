<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Services\Config\ResticSettings;
use Mfonte\Arkhive\Services\Process\ProcessResult;

/**
 * Turns a failed restic run into one precise error with the fix. restic >= 0.17 has dedicated exit
 * codes (10 no repository, 11 locked, 12 wrong password); older versions are recognised by stderr.
 */
final class ResticErrors
{
    public const NO_REPOSITORY = 10;
    public const LOCKED = 11;
    public const WRONG_PASSWORD = 12;

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

            return new TemporaryException(
                "The restic repository is locked{$holder}{$since}",
                hint: 'If no other restic process is using the repository the lock is stale; remove it with: RESTIC_PASSWORD_FILE='
                    . escapeshellarg($settings->passwordFile) . ' restic -r ' . escapeshellarg($settings->repository) . ' unlock (ArkHive never does this automatically)',
            );
        }
        if (stripos($stderr, 'no space left on device') !== false) {
            return new IoException($result->failure($what), hint: 'The filesystem holding the repository (or the restic cache) is full: free space, or prune old snapshots with arkhive maintain');
        }
        if (self::looksLikeSsh($stderr)) {
            return new UnavailableException($result->failure($what), hint: self::reachHint($settings));
        }

        return new ArkhiveException($result->failure($what));
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
