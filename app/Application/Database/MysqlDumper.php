<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\Timeouts;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Backup\DumpResult;
use Mfonte\Arkhive\Domain\Configuration\MysqlConfig;
use Mfonte\Arkhive\Domain\System\Dependency;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * MySQL/MariaDB: one plain dump per database. Credentials go through a temporary
 * --defaults-extra-file (0600), never on the command line.
 */
final class MysqlDumper
{
    /** never dumped by "*" unless listed explicitly */
    public const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'sys', 'mysql'];

    public const DUMP_OPTIONS = ['--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob'];

    private const CONNECT_TIMEOUT = 10;

    private ?SecretFile $credentials = null;
    private ?int $cleanupId = null;

    public function __construct(
        private readonly MysqlConfig $config,
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
    ) {
    }

    /**
     * The option file: values double-quoted, with backslash, quote and control characters escaped as
     * the MySQL/MariaDB option-file parser expects.
     */
    public static function optionFile(MysqlConfig $config): string
    {
        $lines = ['[client]', 'host=' . self::quote($config->host), 'port=' . $config->port, 'user=' . self::quote($config->user)];
        if ($config->password !== null) {
            $lines[] = 'password=' . self::quote($config->password);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The databases to dump: the configured list, with "*" expanded to every database on the server
     * except the system schemas (which are kept when listed by name next to "*").
     *
     * @return list<string>
     */
    public function databases(): array
    {
        $configured = $this->config->databases;
        if (!$this->config->allDatabases()) {
            return $configured;
        }
        $result = $this->query('SHOW DATABASES', 'Listing the MySQL databases');
        $explicit = array_values(array_filter($configured, static fn (string $db): bool => $db !== '*'));
        $databases = [];
        foreach (preg_split('/\R/', trim($result)) ?: [] as $name) {
            if ($name !== '' && (!in_array($name, self::SYSTEM_SCHEMAS, true) || in_array($name, $explicit, true))) {
                $databases[] = $name;
            }
        }
        foreach ($explicit as $name) {
            if (!in_array($name, $databases, true)) {
                $databases[] = $name;
            }
        }

        return $databases;
    }

    /**
     * Approximate size of the databases on the server (data + indexes), for the free-space check.
     *
     * @param list<string> $databases
     */
    public function estimate(array $databases): int
    {
        if ($databases === []) {
            return 0;
        }
        $names = implode(',', array_map(self::sqlString(...), $databases));
        $result = $this->query(
            "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema IN ({$names})",
            'Estimating the MySQL dump size',
        );

        return (int) trim($result);
    }

    public function dump(string $database, DumpStaging $staging): DumpResult
    {
        $binary = $this->dependencies->require(Dependency::MysqlDump, 'to dump MySQL/MariaDB databases (WITH_MYSQL=true)');
        $path = $staging->pathFor('mysql', $database);
        $partial = DumpStaging::partial($path);
        $staging->record($path);

        $command = new Command([
            $binary,
            '--defaults-extra-file=' . $this->credentials()->path,
            ...self::DUMP_OPTIONS,
            '--result-file=' . $partial,
            '--',
            $database,
        ]);
        $result = $this->runner->run($command);
        if (!$result->succeeded()) {
            @unlink($partial);

            throw DatabaseErrors::exception($result, "MySQL dump of database {$database}");
        }
        if (!is_file($partial) || !@rename($partial, $path)) {
            @unlink($partial);

            throw new SoftwareException("MySQL dump of database {$database} reported success but wrote no file at {$partial}");
        }

        return new DumpResult('mysql', $database, $path, (int) filesize($path), $result->duration);
    }

    /**
     * Removes the credentials file; called once all dumps are done (and by the cleanup registry).
     */
    public function close(): void
    {
        if ($this->cleanupId !== null) {
            $this->cleanup->runOne($this->cleanupId);
            $this->cleanupId = null;
        }
        $this->credentials = null;
    }

    private function query(string $sql, string $what): string
    {
        $client = $this->dependencies->require(Dependency::MysqlClient, 'to query MySQL/MariaDB (WITH_MYSQL=true)');
        $result = $this->runner->run((new Command([
            $client,
            '--defaults-extra-file=' . $this->credentials()->path,
            '--connect-timeout=' . self::CONNECT_TIMEOUT,
            '--batch',
            '--skip-column-names',
            '--raw',
            '--execute=' . $sql,
        ]))->withTimeout(Timeouts::QUERY));
        if ($result->timedOut) {
            throw DatabaseErrors::exception($result, $what);
        }
        if (!$result->succeeded()) {
            throw new UnavailableException($result->failure($what), hint: 'Check MYSQL_HOST, MYSQL_PORT, MYSQL_USER and MYSQL_PASSWORD, and that the server is running');
        }

        return $result->stdout;
    }

    private function credentials(): SecretFile
    {
        if ($this->credentials === null) {
            $file = SecretFile::create('arkhive-mysql-', self::optionFile($this->config));
            $this->credentials = $file;
            $this->cleanupId = $this->cleanup->add('remove the MySQL credentials file', $file->remove(...));
        }

        return $this->credentials;
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value) . '"';
    }

    private static function sqlString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
