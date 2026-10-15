<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Configuration;

/**
 * Configuration keys whose values are secrets: a configuration holding any of them must be private
 * to the user ArkHive runs as (the repository password itself lives in RESTIC_PASSWORD_FILE).
 */
final class SecretKeys
{
    public const KEYS = ['MYSQL_PASSWORD', 'PGSQL_PASSWORD', 'SMTP_PASSWORD'];

    /**
     * @param array<string, string> $values
     *
     * @return list<string> the secret keys set to a non-empty value, in the order of KEYS
     */
    public static function found(array $values): array
    {
        return array_values(array_filter(self::KEYS, static fn (string $key): bool => ($values[$key] ?? '') !== ''));
    }
}
