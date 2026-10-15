<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Configuration;

use Mfonte\Arkhive\Application\Configuration\ConfigFactory;
use Mfonte\Arkhive\Application\Configuration\ConfigLoader;
use Mfonte\Arkhive\Application\Configuration\ConfigLocator;
use Mfonte\Arkhive\Application\Configuration\ConfigParser;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\RemovedKeys;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class ConfigFactoryTest extends TestCase
{
    use TempDir;

    private string $passwordFile;

    protected function setUp(): void
    {
        $this->passwordFile = $this->tempDir() . '/password';
        file_put_contents($this->passwordFile, "secret\n");
        chmod($this->passwordFile, 0600);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function create(array $overrides = [], bool $checkSecrets = true): Config
    {
        $values = array_filter($overrides + [
            'BACKUP_PATHS' => '/srv/www /etc',
            'RESTIC_REPOSITORY' => 'sftp:user@backup.example.com:/restic',
            'RESTIC_PASSWORD_FILE' => $this->passwordFile,
        ], static fn (?string $v): bool => $v !== null);

        return ConfigFactory::create($values, '/etc/arkhive/config', 'web01', $checkSecrets);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function assertInvalid(array $overrides, string ...$errors): void
    {
        try {
            $this->create($overrides);
            self::fail('expected a configuration error');
        } catch (ConfigException $e) {
            self::assertSame("Invalid configuration /etc/arkhive/config:\n  " . implode("\n  ", $errors), $e->getMessage());
            self::assertSame(ExitCode::CONFIG, $e->exitCode());
        }
    }

    public function testMinimalConfigGetsTheDocumentedDefaults(): void
    {
        $config = $this->create();

        self::assertSame('/etc/arkhive/config', $config->source);
        self::assertSame(['/srv/www', '/etc'], $config->backupPaths);
        self::assertSame('sftp:user@backup.example.com:/restic', $config->restic->repository);
        self::assertSame($this->passwordFile, $config->restic->passwordFile);
        self::assertNull($config->restic->sftpCommand);
        self::assertSame('restic', $config->restic->binary);
        self::assertSame('web01', $config->restic->host);
        self::assertSame('auto', $config->restic->compression);
        self::assertSame(300, $config->restic->lockWait);
        self::assertSame([7, 4, 6], [$config->keepDaily, $config->keepWeekly, $config->keepMonthly]);
        self::assertSame('5%', $config->checkSubset);
        self::assertNull($config->dumpDirectory);
        self::assertSame([], $config->exclusionPatterns);
        self::assertSame([], $config->resticExcludes);
        self::assertNull($config->mysql);
        self::assertNull($config->pgsql);
        self::assertNull($config->notify);
        self::assertFalse($config->hasDumps());
        self::assertSame([], $config->unknownKeys);
        self::assertFalse($config->dumpRequired);
    }

    public function testEveryResticSettingIsRead(): void
    {
        $config = $this->create([
            'RESTIC_SFTP_COMMAND' => 'ssh -i /root/.ssh/key -p 23 user@backup.example.com -s sftp',
            'RESTIC_BINARY' => '/opt/restic/bin/restic',
            'RESTIC_HOST' => 'web01.example.com',
            'RESTIC_COMPRESSION' => 'max',
            'RESTIC_KEEP_DAILY' => '14',
            'RESTIC_KEEP_WEEKLY' => '0',
            'RESTIC_KEEP_MONTHLY' => '12',
            'RESTIC_CHECK_SUBSET' => '1/10',
            'LOCK_WAIT' => '0',
            'DUMP_REQUIRED' => 'yes',
            'EXCLUSION_PATTERNS' => '*.log,/cache/*',
        ]);

        self::assertSame('ssh -i /root/.ssh/key -p 23 user@backup.example.com -s sftp', $config->restic->sftpCommand);
        self::assertSame('/opt/restic/bin/restic', $config->restic->binary);
        self::assertSame('web01.example.com', $config->restic->host);
        self::assertSame('max', $config->restic->compression);
        self::assertSame([14, 0, 12], [$config->keepDaily, $config->keepWeekly, $config->keepMonthly]);
        self::assertSame('1/10', $config->checkSubset);
        self::assertSame(0, $config->restic->lockWait);
        self::assertTrue($config->dumpRequired);
        self::assertSame(['*.log', '/cache/*'], $config->exclusionPatterns);
        self::assertSame(['/srv/www/**/*.log', '/etc/**/*.log', '/srv/www/cache/*', '/etc/cache/*'], $config->resticExcludes);
    }

    public function testMissingRequiredKeysAreReportedTogether(): void
    {
        $this->assertInvalid(
            ['BACKUP_PATHS' => null, 'RESTIC_REPOSITORY' => '', 'RESTIC_PASSWORD_FILE' => null],
            'BACKUP_PATHS is required',
            'RESTIC_REPOSITORY is required',
            'RESTIC_PASSWORD_FILE is required',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedKeys(): iterable
    {
        foreach (array_keys(RemovedKeys::REPLACEMENTS) as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('removedKeys')]
    public function testEveryRemovedKeyIsRejectedWithItsReplacement(string $key): void
    {
        try {
            $this->create([$key => 'anything']);
            self::fail('expected a configuration error');
        } catch (ConfigException $e) {
            self::assertSame(
                "The configuration /etc/arkhive/config uses keys removed in ArkHive 2.0:\n"
                . "  {$key}: replaced by " . RemovedKeys::REPLACEMENTS[$key] . "\n"
                . 'Migration guide: https://github.com/mauriziofonte/arkhive#migrating-from-1x',
                $e->getMessage(),
            );
            self::assertSame(ExitCode::CONFIG, $e->exitCode());
        }
    }

    public function testThe1xExampleConfigListsEveryRemovedKeyVerbatim(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/config/legacy-1.x.arkhive-config';

        try {
            ConfigFactory::create(ConfigParser::parseFile($path), $path, 'web01');
            self::fail('expected a configuration error');
        } catch (ConfigException $e) {
            self::assertSame(
                "The configuration {$path} uses keys removed in ArkHive 2.0:\n"
                . "  SSH_HOST: replaced by RESTIC_REPOSITORY (e.g. sftp:user@host:/restic), plus RESTIC_SFTP_COMMAND for port and key\n"
                . "  SSH_PORT: replaced by RESTIC_SFTP_COMMAND (e.g. ssh -i /root/.ssh/key -p 23 user@host -s sftp)\n"
                . "  SSH_USER: replaced by RESTIC_REPOSITORY (e.g. sftp:user@host:/restic)\n"
                . "  SSH_BACKUP_HOME: replaced by RESTIC_REPOSITORY (the path after the host, e.g. sftp:user@host:/restic)\n"
                . "  WITH_CRYPT: replaced by RESTIC_PASSWORD_FILE (restic always encrypts)\n"
                . "  CRYPT_PASSWORD: replaced by RESTIC_PASSWORD_FILE; the 1.x password is used only by `arkhive decrypt-legacy --password-file <f>`\n"
                . "  COMPRESSION_TYPE: replaced by RESTIC_COMPRESSION (auto, max or off)\n"
                . "  BACKUP_RETENTION_DAYS: replaced by RESTIC_KEEP_DAILY, RESTIC_KEEP_WEEKLY, RESTIC_KEEP_MONTHLY (applied by `arkhive maintain`)\n"
                . "  BACKUP_DIRECTORY: replaced by BACKUP_PATHS (rename the key: one or more absolute paths, space-separated)\n"
                . "  SMTP_SSL: replaced by SMTP_ENCRYPTION (ssl, tls or none)\n"
                . "  SMTP_AUTH: replaced by nothing: authentication is negotiated automatically when SMTP_USER and SMTP_PASSWORD are set\n"
                . 'Migration guide: https://github.com/mauriziofonte/arkhive#migrating-from-1x',
                $e->getMessage(),
            );
        }
    }

    public function testRemovedKeysWinOverOtherErrors(): void
    {
        $this->expectExceptionMessage('uses keys removed in ArkHive 2.0');

        ConfigFactory::create(['BACKUP_DIRECTORY' => '/srv'], '/etc/arkhive/config', 'web01');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleans(): iterable
    {
        foreach (['true', 'TRUE', 'yes', 'Yes', '1', 'on'] as $value) {
            yield "true: {$value}" => [$value, true];
        }
        foreach (['false', 'no', 'NO', '0', 'off', ''] as $value) {
            yield "false: {$value}" => [$value, false];
        }
    }

    #[DataProvider('booleans')]
    public function testBooleanKeysAcceptTheDocumentedSpellings(string $value, bool $expected): void
    {
        self::assertSame($expected, ConfigFactory::parseBoolean($value));
    }

    public function testInvalidBooleanIsReported(): void
    {
        $this->assertInvalid(['NOTIFY' => 'maybe', 'WITH_PGSQL' => 'sure'], 'WITH_PGSQL must be true or false (also yes/no, 1/0), got "sure"', 'NOTIFY must be true or false (also yes/no, 1/0), got "maybe"');
    }

    public function testOnlyBooleanKeysAreTyped(): void
    {
        $config = $this->create([
            'WITH_MYSQL' => 'yes',
            'MYSQL_USER' => 'true',
            'MYSQL_PASSWORD' => 'yes',
            'MYSQL_DATABASES' => 'no',
            'DUMP_DIRECTORY' => '/var/backups/dumps',
            'RESTIC_HOST' => '1',
        ]);

        self::assertNotNull($config->mysql);
        self::assertSame('true', $config->mysql->user);
        self::assertSame('yes', $config->mysql->password);
        self::assertSame(['no'], $config->mysql->databases);
        self::assertSame('1', $config->restic->host);
    }

    public function testGroupReadablePasswordFileIsRefused(): void
    {
        chmod($this->passwordFile, 0640);

        $this->assertInvalid([], "RESTIC_PASSWORD_FILE {$this->passwordFile} is readable by group or others (mode 0640) (Run: chmod 600 {$this->passwordFile})");
    }

    public function testWorldReadablePasswordFileIsRefused(): void
    {
        chmod($this->passwordFile, 0604);

        $this->assertInvalid([], "RESTIC_PASSWORD_FILE {$this->passwordFile} is readable by group or others (mode 0604) (Run: chmod 600 {$this->passwordFile})");
    }

    public function testPasswordFileOwnedBySomeoneElseIsRefused(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('changing the owner of a file needs root');
        }
        chown($this->passwordFile, 65534);

        $this->assertInvalid([], "RESTIC_PASSWORD_FILE {$this->passwordFile} is owned by uid 65534, not by the running user (uid 0) (Run: chown 0 {$this->passwordFile})");
    }

    public function testMissingAndRelativePasswordFiles(): void
    {
        $this->assertInvalid(['RESTIC_PASSWORD_FILE' => '/nonexistent/pw'], 'RESTIC_PASSWORD_FILE /nonexistent/pw does not exist');
        $this->assertInvalid(['RESTIC_PASSWORD_FILE' => 'pw'], 'RESTIC_PASSWORD_FILE must be an absolute path, got "pw"');
    }

    public function testSecretChecksCanBeSkipped(): void
    {
        chmod($this->passwordFile, 0644);

        self::assertSame($this->passwordFile, $this->create([], false)->restic->passwordFile);
    }

    public function testBackupPathsAcceptQuotesAndEscapes(): void
    {
        $config = $this->create(['BACKUP_PATHS' => '  /srv/www/  "/home/my files" /data/with\\ space /srv/www /']);

        self::assertSame(['/srv/www', '/home/my files', '/data/with space', '/'], $config->backupPaths);
    }

    public function testRelativeBackupPathsAreRefused(): void
    {
        $this->assertInvalid(['BACKUP_PATHS' => '/srv relative ./x'], 'BACKUP_PATHS entries must be absolute paths, got "relative"', 'BACKUP_PATHS entries must be absolute paths, got "./x"');
        $this->assertInvalid(['BACKUP_PATHS' => '""'], 'BACKUP_PATHS entries must be absolute paths, got ""');
    }

    public function testSplitWords(): void
    {
        self::assertSame(['a', 'b c', 'd e', 'f"g'], ConfigFactory::splitWords(" a \"b c\"\td\\ e f\\\"g "));
        self::assertSame([], ConfigFactory::splitWords('   '));
    }

    public function testLoggingKeys(): void
    {
        $default = $this->create();
        self::assertNull($default->logTarget, 'auto');
        self::assertSame(SyslogFacility::Daemon, $default->syslogFacility);

        self::assertNull($this->create(['LOG_TARGET' => 'auto'])->logTarget);
        $syslog = $this->create(['LOG_TARGET' => 'syslog', 'SYSLOG_FACILITY' => 'local5']);
        self::assertSame(LogTarget::Syslog, $syslog->logTarget);
        self::assertSame(SyslogFacility::Local5, $syslog->syslogFacility);
        self::assertSame([], $syslog->unknownKeys);

        $this->assertInvalid(
            ['LOG_TARGET' => 'file', 'SYSLOG_FACILITY' => 'kern'],
            'LOG_TARGET must be auto, stderr, journal or syslog, got "file"',
            'SYSLOG_FACILITY must be user, daemon or local0 to local7, got "kern"',
        );
    }

    public function testInvalidResticValues(): void
    {
        $this->assertInvalid(
            ['RESTIC_COMPRESSION' => 'gzip', 'RESTIC_CHECK_SUBSET' => 'half', 'RESTIC_KEEP_DAILY' => '-1', 'RESTIC_KEEP_MONTHLY' => '6.5', 'LOCK_WAIT' => '5m', 'DUMP_REQUIRED' => 'maybe'],
            'RESTIC_COMPRESSION must be auto, max or off, got "gzip"',
            'LOCK_WAIT must be a whole number >= 0, got "5m"',
            'RESTIC_CHECK_SUBSET must be a percentage (5%), a fraction (1/10) or a size (500M), got "half"',
            'RESTIC_KEEP_DAILY must be a whole number >= 0, got "-1"',
            'RESTIC_KEEP_MONTHLY must be a whole number >= 0, got "6.5"',
            'DUMP_REQUIRED must be true or false (also yes/no, 1/0), got "maybe"',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validSubsets(): iterable
    {
        foreach (['5%', '2.5%', '1/10', '500M', '2G', '1024'] as $subset) {
            yield $subset => [$subset];
        }
    }

    #[DataProvider('validSubsets')]
    public function testValidCheckSubsets(string $subset): void
    {
        self::assertSame($subset, $this->create(['RESTIC_CHECK_SUBSET' => $subset])->checkSubset);
    }

    public function testMysql(): void
    {
        $config = $this->create(['WITH_MYSQL' => 'true', 'MYSQL_USER' => 'backup', 'MYSQL_DATABASES' => '*', 'DUMP_DIRECTORY' => '/var/backups/dumps/']);

        self::assertNotNull($config->mysql);
        self::assertSame(['localhost', 3306, 'backup', null, ['*']], [$config->mysql->host, $config->mysql->port, $config->mysql->user, $config->mysql->password, $config->mysql->databases]);
        self::assertTrue($config->mysql->allDatabases());
        self::assertSame('/var/backups/dumps', $config->dumpDirectory);
        self::assertTrue($config->hasDumps());
    }

    public function testMysqlRequiresUserDatabasesAndADumpDirectory(): void
    {
        $this->assertInvalid(
            ['WITH_MYSQL' => '1', 'MYSQL_PORT' => '99999'],
            'MYSQL_DATABASES is required when WITH_MYSQL is enabled',
            'MYSQL_PORT must be between 1 and 65535, got 99999',
            'MYSQL_USER is required when WITH_MYSQL is enabled',
            'DUMP_DIRECTORY is required when WITH_MYSQL or WITH_PGSQL is enabled',
        );
    }

    public function testPgsql(): void
    {
        $config = $this->create([
            'WITH_PGSQL' => 'yes', 'PGSQL_USER' => 'postgres', 'PGSQL_PASSWORD' => 'p@ss:w/rd', 'PGSQL_PORT' => '5433',
            'PGSQL_DATABASES' => 'inventory crm', 'DUMP_DIRECTORY' => '/var/backups/dumps',
        ]);

        self::assertNotNull($config->pgsql);
        self::assertSame(['localhost', 5433, 'postgres', 'p@ss:w/rd', ['inventory', 'crm'], ['--no-owner', '--no-privileges']], [
            $config->pgsql->host, $config->pgsql->port, $config->pgsql->user, $config->pgsql->password, $config->pgsql->databases, $config->pgsql->dumpOptions,
        ]);
    }

    public function testPgsqlDumpOptions(): void
    {
        $base = ['WITH_PGSQL' => '1', 'PGSQL_USER' => 'pg', 'PGSQL_DATABASES' => 'a', 'DUMP_DIRECTORY' => '/var/dumps'];

        self::assertSame(['--no-owner'], $this->create($base + ['PGSQL_DUMP_OPTIONS' => '--no-owner'])->pgsql?->dumpOptions);
        self::assertSame([], $this->create($base + ['PGSQL_DUMP_OPTIONS' => ''])->pgsql?->dumpOptions);
        $this->assertInvalid($base + ['PGSQL_DUMP_OPTIONS' => '--no-owner -f /tmp/x'], 'PGSQL_DUMP_OPTIONS accepts only long options (--name or --name=value), got "-f"', 'PGSQL_DUMP_OPTIONS accepts only long options (--name or --name=value), got "/tmp/x"');
        $this->assertInvalid(array_merge($base, ['PGSQL_DATABASES' => '*']), 'PGSQL_DATABASES must list the databases by name ("*" is supported only for MySQL)');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badDumpDirectories(): iterable
    {
        yield 'relative' => ['dumps', 'DUMP_DIRECTORY must be an absolute path, got "dumps"'];
        yield 'root' => ['/', 'DUMP_DIRECTORY cannot be /'];
        yield 'a backup path' => ['/srv/www', 'DUMP_DIRECTORY /srv/www contains the backup path /srv/www: ArkHive empties DUMP_DIRECTORY after each backup, so it must be a dedicated directory'];
        yield 'above a backup path' => ['/srv', 'DUMP_DIRECTORY /srv contains the backup path /srv/www: ArkHive empties DUMP_DIRECTORY after each backup, so it must be a dedicated directory'];
        yield 'excluded' => ['/srv/www/tmp/dumps', 'DUMP_DIRECTORY /srv/www/tmp/dumps is excluded by EXCLUSION_PATTERNS, so the dumps would never reach the snapshot'];
        yield 'excluded by a file pattern' => ['/srv/www/dumps', 'DUMP_DIRECTORY /srv/www/dumps is excluded by EXCLUSION_PATTERNS, so the dumps would never reach the snapshot'];
    }

    #[DataProvider('badDumpDirectories')]
    public function testDumpDirectoryMustBeDedicatedAndIncluded(string $dir, string $error): void
    {
        $this->assertInvalid(
            ['WITH_MYSQL' => '1', 'MYSQL_USER' => 'u', 'MYSQL_DATABASES' => 'a', 'DUMP_DIRECTORY' => $dir, 'EXCLUSION_PATTERNS' => '*/tmp/*,*.sql'],
            ...(str_contains($error, 'excluded') || $dir !== '/srv/www/dumps' ? [$error] : []),
        );
    }

    public function testDumpDirectoryInsideABackupPathIsAllowed(): void
    {
        self::assertSame('/srv/www/private/dumps', $this->create(['WITH_MYSQL' => '1', 'MYSQL_USER' => 'u', 'MYSQL_DATABASES' => 'a', 'DUMP_DIRECTORY' => '/srv/www/private/dumps'])->dumpDirectory);
    }

    public function testNotifyDefaultsTheSmtpPortFromTheEncryption(): void
    {
        $base = ['NOTIFY' => 'true', 'SMTP_HOST' => 'smtp.example.com', 'SMTP_FROM' => 'arkhive@example.com', 'SMTP_TO' => 'a@example.com, b@example.com'];

        $tls = $this->create($base)->notify;
        self::assertNotNull($tls);
        self::assertSame([false, 'smtp.example.com', 587, 'tls', null, null, 'arkhive@example.com', ['a@example.com', 'b@example.com']], [
            $tls->onlyOnFailure, $tls->host, $tls->port, $tls->encryption, $tls->user, $tls->password, $tls->from, $tls->to,
        ]);
        self::assertSame(465, $this->create($base + ['SMTP_ENCRYPTION' => 'ssl'])->notify?->port);
        self::assertSame(25, $this->create($base + ['SMTP_ENCRYPTION' => 'none'])->notify?->port);
        self::assertSame(2525, $this->create($base + ['SMTP_PORT' => '2525'])->notify?->port);

        $failure = $this->create($base + ['NOTIFY_ON' => 'failure', 'SMTP_USER' => 'u', 'SMTP_PASSWORD' => 'p'])->notify;
        self::assertNotNull($failure);
        self::assertTrue($failure->onlyOnFailure);
        self::assertSame(['u', 'p'], [$failure->user, $failure->password]);
    }

    public function testNotifyValidation(): void
    {
        $this->assertInvalid(
            ['NOTIFY' => 'yes', 'NOTIFY_ON' => 'sometimes', 'SMTP_ENCRYPTION' => 'starttls', 'SMTP_FROM' => 'not-an-address', 'SMTP_TO' => 'a@example.com,broken'],
            'NOTIFY_ON must be always or failure, got "sometimes"',
            'SMTP_ENCRYPTION must be ssl, tls or none, got "starttls"',
            '"not-an-address" is not a valid e-mail address (SMTP_FROM / SMTP_TO)',
            '"broken" is not a valid e-mail address (SMTP_FROM / SMTP_TO)',
            'SMTP_HOST is required when NOTIFY is enabled',
        );
        $this->assertInvalid(['NOTIFY' => 'yes', 'SMTP_HOST' => 'h'], 'SMTP_FROM is required when NOTIFY is enabled', 'SMTP_TO is required when NOTIFY is enabled');
    }

    public function testUnknownKeysAreListed(): void
    {
        self::assertSame(['BACKUP_PATH', 'RESTIC_KEEP_YEARLY'], $this->create(['BACKUP_PATH' => '/x', 'RESTIC_KEEP_YEARLY' => '1'])->unknownKeys);
    }

    public function testLoaderLocatesParsesAndValidates(): void
    {
        $cwd = $this->tempDir();
        file_put_contents("{$cwd}/.arkhive-config", "BACKUP_PATHS=/srv\nRESTIC_REPOSITORY=/var/restic\nRESTIC_PASSWORD_FILE={$this->passwordFile}\n");
        $loader = new ConfigLoader(new ConfigLocator($cwd, ''), 'web02');

        $config = $loader->load();
        self::assertSame("{$cwd}/.arkhive-config", $config->source);
        self::assertSame('web02', $config->restic->host);
        self::assertSame("{$cwd}/.arkhive-config", $loader->locator()->locate());
    }

    public function testTheLoaderWarnsAboutPasswordsOthersCanReadAndKeepsRunning(): void
    {
        $dir = $this->tempDir();
        $path = "{$dir}/config";
        file_put_contents($path, "BACKUP_PATHS=/srv\nRESTIC_REPOSITORY=/var/restic\nRESTIC_PASSWORD_FILE={$this->passwordFile}\nMYSQL_PASSWORD=db-password-placeholder\n");
        $loader = new ConfigLoader(new ConfigLocator($dir, ''), 'web02');

        chmod($path, 0600);
        self::assertSame([], $loader->load($path)->warnings);

        chmod($path, 0644);
        self::assertSame(
            ["The configuration {$path} holds MYSQL_PASSWORD and is readable by group or others (mode 0644): Run: chmod 600 {$path} (a later ArkHive version will refuse it)"],
            $loader->load($path)->warnings,
        );

        file_put_contents($path, "BACKUP_PATHS=/srv\nRESTIC_REPOSITORY=/var/restic\nRESTIC_PASSWORD_FILE={$this->passwordFile}\n");
        self::assertSame([], $loader->load($path)->warnings, 'no passwords in it');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function changeableModes(): iterable
    {
        yield 'group-writable' => [0660];
        yield 'world-writable' => [0666];
        yield 'writable by others only' => [0602];
    }

    #[DataProvider('changeableModes')]
    public function testTheLoaderRefusesAConfigurationOthersCanChange(int $mode): void
    {
        $dir = $this->tempDir();
        $path = "{$dir}/config";
        file_put_contents($path, "BACKUP_PATHS=/srv\nRESTIC_REPOSITORY=/var/restic\nRESTIC_PASSWORD_FILE={$this->passwordFile}\n");
        chmod($path, $mode);

        try {
            (new ConfigLoader(new ConfigLocator($dir, ''), 'web02'))->load($path);
            self::fail('a configuration others can write was loaded');
        } catch (PermissionException $e) {
            self::assertSame(ExitCode::NO_PERMISSION, $e->exitCode());
            self::assertSame(sprintf('The configuration %s is writable by group or others (mode %04o): whoever can change it chooses the commands ArkHive runs', $path, $mode), $e->getMessage());
            self::assertSame("Run: chmod 600 {$path}", $e->hint());
        }
    }

    public function testRootRefusesAConfigurationAnotherUserOwns(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('needs root to give the file to another user');
        }
        $dir = $this->tempDir();
        $path = "{$dir}/config";
        file_put_contents($path, "BACKUP_PATHS=/srv\nRESTIC_REPOSITORY=/var/restic\nRESTIC_PASSWORD_FILE={$this->passwordFile}\n");
        chmod($path, 0600);
        chown($path, 65534);

        try {
            (new ConfigLoader(new ConfigLocator($dir, ''), 'web02'))->load($path);
            self::fail('a configuration another user owns was loaded');
        } catch (PermissionException $e) {
            self::assertSame("The configuration {$path} belongs to uid 65534, not to the running user (uid 0) or root: that user can change it and so choose the commands ArkHive runs", $e->getMessage());
            self::assertSame("Run: chown 0 {$path} && chmod 600 {$path}", $e->hint());
        }
    }
}
