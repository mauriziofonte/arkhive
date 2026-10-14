<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Config;

/**
 * 1.x keys dismissed in 2.0 (spec §3, decision 2). A config containing any of them fails preflight.
 */
final class RemovedKeys
{
    public const MIGRATION_URL = 'https://github.com/mauriziofonte/arkhive#migrating-from-1x';

    /** @var array<string, string> removed key => what replaces it */
    public const REPLACEMENTS = [
        'SSH_HOST' => 'RESTIC_REPOSITORY (e.g. sftp:user@host:/restic), plus RESTIC_SFTP_COMMAND for port and key',
        'SSH_PORT' => 'RESTIC_SFTP_COMMAND (e.g. ssh -i /root/.ssh/key -p 23 user@host -s sftp)',
        'SSH_USER' => 'RESTIC_REPOSITORY (e.g. sftp:user@host:/restic)',
        'SSH_BACKUP_HOME' => 'RESTIC_REPOSITORY (the path after the host, e.g. sftp:user@host:/restic)',
        'WITH_CRYPT' => 'RESTIC_PASSWORD_FILE (restic always encrypts)',
        'CRYPT_PASSWORD' => 'RESTIC_PASSWORD_FILE; the 1.x password is used only by `arkhive decrypt-legacy --password-file <f>`',
        'COMPRESSION_TYPE' => 'RESTIC_COMPRESSION (auto, max or off)',
        'BACKUP_RETENTION_DAYS' => 'RESTIC_KEEP_DAILY, RESTIC_KEEP_WEEKLY, RESTIC_KEEP_MONTHLY (applied by `arkhive maintain`)',
        'BACKUP_DIRECTORY' => 'BACKUP_PATHS (rename the key: one or more absolute paths, space-separated)',
        'SMTP_SSL' => 'SMTP_ENCRYPTION (ssl, tls or none)',
        'SMTP_AUTH' => 'nothing: authentication is negotiated automatically when SMTP_USER and SMTP_PASSWORD are set',
    ];

    /**
     * @param array<string, string> $values
     *
     * @return list<string> the removed keys present, in the order of the table
     */
    public static function found(array $values): array
    {
        return array_values(array_filter(
            array_keys(self::REPLACEMENTS),
            static fn (string $key): bool => array_key_exists($key, $values),
        ));
    }

    /**
     * @param list<string> $found
     */
    public static function message(array $found, string $source): string
    {
        $lines = ["The configuration {$source} uses keys removed in ArkHive 2.0:"];
        foreach ($found as $key) {
            $lines[] = "  {$key}: replaced by " . self::REPLACEMENTS[$key];
        }
        $lines[] = 'Migration guide: ' . self::MIGRATION_URL;

        return implode("\n", $lines);
    }
}
