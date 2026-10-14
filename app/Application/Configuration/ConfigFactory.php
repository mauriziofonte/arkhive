<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Configuration;

use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\MysqlConfig;
use Mfonte\Arkhive\Domain\Configuration\NotifyConfig;
use Mfonte\Arkhive\Domain\Configuration\PgsqlConfig;
use Mfonte\Arkhive\Domain\Configuration\RemovedKeys;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Domain\Exclusion\ResticPatternMatcher;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\ConfigException;

/**
 * Validates raw configuration values (spec §3, §4) into a typed Config. Every problem is collected and
 * reported at once, so a 1.x config is fixed in one pass.
 */
final class ConfigFactory
{
    /** only these keys are converted from true/false/yes/no/1/0; every other value stays a string */
    public const BOOLEAN_KEYS = ['WITH_MYSQL', 'WITH_PGSQL', 'DUMP_REQUIRED', 'NOTIFY'];

    public const KNOWN_KEYS = [
        'BACKUP_PATHS', 'RESTIC_REPOSITORY', 'RESTIC_PASSWORD_FILE', 'RESTIC_SFTP_COMMAND', 'RESTIC_BINARY',
        'RESTIC_HOST', 'RESTIC_KEEP_DAILY', 'RESTIC_KEEP_WEEKLY', 'RESTIC_KEEP_MONTHLY', 'RESTIC_CHECK_SUBSET',
        'RESTIC_COMPRESSION', 'LOCK_WAIT', 'DUMP_DIRECTORY', 'DUMP_REQUIRED', 'EXCLUSION_PATTERNS',
        'WITH_MYSQL', 'MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASES',
        'WITH_PGSQL', 'PGSQL_HOST', 'PGSQL_PORT', 'PGSQL_USER', 'PGSQL_PASSWORD', 'PGSQL_DATABASES', 'PGSQL_DUMP_OPTIONS',
        'NOTIFY', 'NOTIFY_ON', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_FROM', 'SMTP_TO',
    ];

    public const DEFAULT_PGSQL_DUMP_OPTIONS = '--no-owner --no-privileges';

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param array<string, string> $values
     */
    private function __construct(private readonly array $values, private readonly string $source)
    {
    }

    /**
     * @param array<string, string> $values      raw values from ConfigParser
     * @param bool                  $checkSecrets check the password file permissions (off only for doctor's own reporting)
     */
    public static function create(array $values, string $source, string $defaultHost, bool $checkSecrets = true): Config
    {
        $removed = RemovedKeys::found($values);
        if ($removed !== []) {
            throw new ConfigException(RemovedKeys::message($removed, $source));
        }

        return (new self($values, $source))->build($defaultHost, $checkSecrets);
    }

    public static function parseBoolean(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'true', 'yes', '1', 'on' => true,
            'false', 'no', '0', 'off', '' => false,
            default => null,
        };
    }

    /**
     * Splits a space-separated list; double quotes or a backslash keep spaces inside one item.
     *
     * @return list<string>
     */
    public static function splitWords(string $value): array
    {
        $words = [];
        $current = '';
        $inWord = false;
        $quoted = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $value[++$i];
                $inWord = true;
            } elseif ($char === '"') {
                $quoted = !$quoted;
                $inWord = true;
            } elseif (!$quoted && ctype_space($char)) {
                if ($inWord) {
                    $words[] = $current;
                }
                $current = '';
                $inWord = false;
            } else {
                $current .= $char;
                $inWord = true;
            }
        }
        if ($inWord) {
            $words[] = $current;
        }

        return $words;
    }

    private function build(string $defaultHost, bool $checkSecrets): Config
    {
        $backupPaths = $this->backupPaths();
        $repository = $this->required('RESTIC_REPOSITORY');
        $passwordFile = $this->required('RESTIC_PASSWORD_FILE');
        if ($passwordFile !== '' && !str_starts_with($passwordFile, '/')) {
            $this->errors[] = "RESTIC_PASSWORD_FILE must be an absolute path, got \"{$passwordFile}\"";
        } elseif ($passwordFile !== '' && $checkSecrets) {
            try {
                PasswordFile::assertSafe($passwordFile);
            } catch (ArkhiveException $e) {
                $this->errors[] = $e->getMessage() . ($e->hint() !== null ? " ({$e->hint()})" : '');
            }
        }

        $compression = $this->optional('RESTIC_COMPRESSION') ?? 'auto';
        if (!in_array($compression, ['auto', 'max', 'off'], true)) {
            $this->errors[] = "RESTIC_COMPRESSION must be auto, max or off, got \"{$compression}\"";
        }

        $restic = new ResticSettings(
            $repository,
            $passwordFile,
            $this->optional('RESTIC_SFTP_COMMAND'),
            $this->optional('RESTIC_BINARY') ?? 'restic',
            $this->optional('RESTIC_HOST') ?? $defaultHost,
            $compression,
            $this->integer('LOCK_WAIT', ResticSettings::DEFAULT_LOCK_WAIT),
        );

        $checkSubset = $this->optional('RESTIC_CHECK_SUBSET') ?? '5%';
        if (preg_match('#^(\d+(\.\d+)?%|\d+/\d+|\d+[kKmMgGtT]?)$#', $checkSubset) !== 1) {
            $this->errors[] = "RESTIC_CHECK_SUBSET must be a percentage (5%), a fraction (1/10) or a size (500M), got \"{$checkSubset}\"";
        }

        $exclusions = ExclusionConverter::split($this->values['EXCLUSION_PATTERNS'] ?? '');
        $excludes = ExclusionConverter::convert($exclusions, $backupPaths);

        $mysql = $this->boolean('WITH_MYSQL') ? $this->mysql() : null;
        $pgsql = $this->boolean('WITH_PGSQL') ? $this->pgsql() : null;
        $dumpDirectory = $this->dumpDirectory($mysql !== null || $pgsql !== null, $backupPaths, $excludes);
        $notify = $this->boolean('NOTIFY') ? $this->notify() : null;

        $config = new Config(
            $this->source,
            $backupPaths,
            $restic,
            $this->integer('RESTIC_KEEP_DAILY', 7),
            $this->integer('RESTIC_KEEP_WEEKLY', 4),
            $this->integer('RESTIC_KEEP_MONTHLY', 6),
            $checkSubset,
            $dumpDirectory,
            $exclusions,
            $excludes,
            $mysql,
            $pgsql,
            $notify,
            array_values(array_diff(array_keys($this->values), self::KNOWN_KEYS)),
            $this->boolean('DUMP_REQUIRED'),
        );

        if ($this->errors !== []) {
            throw new ConfigException("Invalid configuration {$this->source}:\n  " . implode("\n  ", $this->errors));
        }

        return $config;
    }

    /**
     * @return list<string>
     */
    private function backupPaths(): array
    {
        $raw = $this->required('BACKUP_PATHS');
        $paths = [];
        foreach (self::splitWords($raw) as $path) {
            if (!str_starts_with($path, '/')) {
                $this->errors[] = "BACKUP_PATHS entries must be absolute paths, got \"{$path}\"";

                continue;
            }
            $paths[] = $path === '/' ? '/' : rtrim($path, '/');
        }
        if ($raw !== '' && $paths === [] && $this->errors === []) {
            $this->errors[] = 'BACKUP_PATHS is empty';
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $backupPaths
     * @param list<string> $excludes
     */
    private function dumpDirectory(bool $dumps, array $backupPaths, array $excludes): ?string
    {
        $dir = $this->optional('DUMP_DIRECTORY');
        if ($dir === null) {
            if ($dumps) {
                $this->errors[] = 'DUMP_DIRECTORY is required when WITH_MYSQL or WITH_PGSQL is enabled';
            }

            return null;
        }
        if (!str_starts_with($dir, '/')) {
            $this->errors[] = "DUMP_DIRECTORY must be an absolute path, got \"{$dir}\"";

            return null;
        }
        $dir = rtrim($dir, '/');
        if ($dir === '') {
            $this->errors[] = 'DUMP_DIRECTORY cannot be /';

            return null;
        }
        foreach ($backupPaths as $path) {
            if ($path === $dir || $path === '/' || str_starts_with($path . '/', $dir . '/')) {
                $this->errors[] = "DUMP_DIRECTORY {$dir} contains the backup path {$path}: ArkHive empties DUMP_DIRECTORY after each backup, so it must be a dedicated directory";

                return null;
            }
        }
        foreach ([$dir, "{$dir}/mysql/database.sql", "{$dir}/pgsql/database.sql"] as $probe) {
            if (ResticPatternMatcher::excludes($excludes, $probe)) {
                $this->errors[] = "DUMP_DIRECTORY {$dir} is excluded by EXCLUSION_PATTERNS, so the dumps would never reach the snapshot";

                return null;
            }
        }

        return $dir;
    }

    private function mysql(): MysqlConfig
    {
        $databases = self::splitWords($this->required('MYSQL_DATABASES', 'when WITH_MYSQL is enabled'));

        return new MysqlConfig(
            $this->optional('MYSQL_HOST') ?? 'localhost',
            $this->port('MYSQL_PORT', 3306),
            $this->required('MYSQL_USER', 'when WITH_MYSQL is enabled'),
            $this->optional('MYSQL_PASSWORD'),
            $databases,
        );
    }

    private function pgsql(): PgsqlConfig
    {
        $options = self::splitWords($this->values['PGSQL_DUMP_OPTIONS'] ?? self::DEFAULT_PGSQL_DUMP_OPTIONS);
        foreach ($options as $option) {
            if (!str_starts_with($option, '--')) {
                $this->errors[] = "PGSQL_DUMP_OPTIONS accepts only long options (--name or --name=value), got \"{$option}\"";
            }
        }
        $databases = self::splitWords($this->required('PGSQL_DATABASES', 'when WITH_PGSQL is enabled'));
        if (in_array('*', $databases, true)) {
            $this->errors[] = 'PGSQL_DATABASES must list the databases by name ("*" is supported only for MySQL)';
        }

        return new PgsqlConfig(
            $this->optional('PGSQL_HOST') ?? 'localhost',
            $this->port('PGSQL_PORT', 5432),
            $this->required('PGSQL_USER', 'when WITH_PGSQL is enabled'),
            $this->optional('PGSQL_PASSWORD'),
            $databases,
            $options,
        );
    }

    private function notify(): NotifyConfig
    {
        $on = $this->optional('NOTIFY_ON') ?? 'always';
        if (!in_array($on, ['always', 'failure'], true)) {
            $this->errors[] = "NOTIFY_ON must be always or failure, got \"{$on}\"";
        }
        $encryption = $this->optional('SMTP_ENCRYPTION') ?? 'tls';
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            $this->errors[] = "SMTP_ENCRYPTION must be ssl, tls or none, got \"{$encryption}\"";
            $encryption = 'tls';
        }
        $defaultPort = match ($encryption) {
            'ssl' => 465,
            'tls' => 587,
            default => 25,
        };

        $from = $this->required('SMTP_FROM', 'when NOTIFY is enabled');
        $to = array_values(array_filter(array_map('trim', explode(',', $this->required('SMTP_TO', 'when NOTIFY is enabled')))));
        foreach (array_merge($from === '' ? [] : [$from], $to) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->errors[] = "\"{$address}\" is not a valid e-mail address (SMTP_FROM / SMTP_TO)";
            }
        }

        return new NotifyConfig(
            $on === 'failure',
            $this->required('SMTP_HOST', 'when NOTIFY is enabled'),
            $this->port('SMTP_PORT', $defaultPort),
            $encryption,
            $this->optional('SMTP_USER'),
            $this->optional('SMTP_PASSWORD'),
            $from,
            $to,
        );
    }

    private function required(string $key, string $when = ''): string
    {
        $value = $this->values[$key] ?? '';
        if ($value === '') {
            $this->errors[] = trim("{$key} is required {$when}");
        }

        return $value;
    }

    private function optional(string $key): ?string
    {
        $value = $this->values[$key] ?? '';

        return $value === '' ? null : $value;
    }

    private function boolean(string $key): bool
    {
        $value = self::parseBoolean($this->values[$key] ?? '');
        if ($value === null) {
            $this->errors[] = "{$key} must be true or false (also yes/no, 1/0), got \"{$this->values[$key]}\"";

            return false;
        }

        return $value;
    }

    private function integer(string $key, int $default): int
    {
        $value = $this->optional($key);
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit($value)) {
            $this->errors[] = "{$key} must be a whole number >= 0, got \"{$value}\"";

            return $default;
        }

        return (int) $value;
    }

    private function port(string $key, int $default): int
    {
        $port = $this->integer($key, $default);
        if ($port < 1 || $port > 65535) {
            $this->errors[] = "{$key} must be between 1 and 65535, got {$port}";
        }

        return $port;
    }
}
