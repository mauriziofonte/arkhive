<?php

declare(strict_types=1);

namespace Tests\e2e;

use Mfonte\Arkhive\Application\Database\MysqlDumper;
use Mfonte\Arkhive\Application\Database\PgsqlDumper;
use Mfonte\Arkhive\Application\Database\SecretFile;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\System\BinaryFinder;
use Mfonte\Arkhive\Domain\Configuration\MysqlConfig;
use Mfonte\Arkhive\Domain\Configuration\PgsqlConfig;
use Mfonte\Arkhive\Infrastructure\Process\Signals;
use Mfonte\Arkhive\Infrastructure\Process\SystemProcessRunner;

/**
 * Queries against the seeded MariaDB and PostgreSQL servers, credentials through files as ArkHive does.
 */
trait DatabaseClients
{
    protected function mysqlRoot(): MysqlConfig
    {
        return new MysqlConfig(self::env('E2E_MYSQL_HOST'), (int) self::env('E2E_MYSQL_PORT'), self::env('E2E_MYSQL_USER'), self::env('E2E_MYSQL_PASSWORD'), ['*']);
    }

    protected function pgsqlRoot(): PgsqlConfig
    {
        return new PgsqlConfig(self::env('E2E_PGSQL_HOST'), (int) self::env('E2E_PGSQL_PORT'), self::env('E2E_PGSQL_USER'), self::env('E2E_PGSQL_PASSWORD'), ['inventory', 'crm'], ['--no-owner', '--no-privileges']);
    }

    protected function mysql(string $sql, ?string $database = null, ?string $inputFile = null): string
    {
        $finder = BinaryFinder::fromEnvironment();
        $credentials = SecretFile::create('e2e-mysql-', MysqlDumper::optionFile($this->mysqlRoot()));
        try {
            $argv = [(string) $finder->first(['mariadb', 'mysql']), '--defaults-extra-file=' . $credentials->path, '--batch', '--skip-column-names'];
            if ($inputFile === null) {
                $argv[] = '--execute=' . $sql;
            }
            if ($database !== null) {
                $argv[] = $database;
            }
            $result = (new SystemProcessRunner(new Signals()))->run(new Command($argv, inputFile: $inputFile));
            self::assertSame(0, $result->exitCode, $result->stderr);

            return trim($result->stdout);
        } finally {
            $credentials->remove();
        }
    }

    /**
     * CHECKSUM TABLE value (the table name column dropped).
     */
    protected function checksum(string $table): string
    {
        $row = explode("\t", $this->mysql("CHECKSUM TABLE {$table}"));

        return $row[1] ?? '';
    }

    protected function psql(string $sql, string $database = 'postgres', ?string $file = null): string
    {
        $config = $this->pgsqlRoot();
        $passfile = SecretFile::create('e2e-pgpass-', PgsqlDumper::passfileLine($config));
        try {
            $result = (new SystemProcessRunner(new Signals()))->run(new Command([
                (string) BinaryFinder::fromEnvironment()->find('psql'),
                "--host={$config->host}", "--port={$config->port}", "--username={$config->user}", '--no-password',
                "--dbname={$database}", '--no-psqlrc', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1', '--quiet',
                ...($file === null ? ["--command={$sql}"] : ["--file={$file}"]),
            ], ['PGPASSFILE' => $passfile->path]));
            self::assertSame(0, $result->exitCode, $result->stderr);

            return trim($result->stdout);
        } finally {
            $passfile->remove();
        }
    }

    /**
     * Rows and a content hash of a PostgreSQL table.
     */
    protected function pgFingerprint(string $database, string $table): string
    {
        return $this->psql("SELECT COUNT(*) || ':' || md5(string_agg(t::text, ',' ORDER BY t.id)) FROM {$table} t", $database);
    }

    /**
     * @return array<string, string> the MySQL/MariaDB settings of an ArkHive configuration
     */
    protected function databaseConfig(string $dumpDirectory): array
    {
        return [
            'DUMP_DIRECTORY' => $dumpDirectory,
            'WITH_MYSQL' => 'true',
            'MYSQL_HOST' => self::env('E2E_MYSQL_HOST'),
            'MYSQL_PORT' => self::env('E2E_MYSQL_PORT'),
            'MYSQL_USER' => self::env('E2E_MYSQL_USER'),
            'MYSQL_PASSWORD' => self::env('E2E_MYSQL_PASSWORD'),
            'MYSQL_DATABASES' => 'shop blog',
            'WITH_PGSQL' => 'true',
            'PGSQL_HOST' => self::env('E2E_PGSQL_HOST'),
            'PGSQL_PORT' => self::env('E2E_PGSQL_PORT'),
            'PGSQL_USER' => self::env('E2E_PGSQL_USER'),
            'PGSQL_PASSWORD' => self::env('E2E_PGSQL_PASSWORD'),
            'PGSQL_DATABASES' => 'inventory crm',
        ];
    }
}
