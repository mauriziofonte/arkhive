<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\MysqlConfig;
use Mfonte\Arkhive\Domain\Configuration\PgsqlConfig;
use Mfonte\Arkhive\Domain\System\Dependency;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * --db <name> --import-as <new>: loads a restored dump into a NEW database on the configured server.
 * An existing database is refused unless forced; a database created here is dropped again if the
 * import fails, so a failed import leaves the server as it was. Credentials as for the dumps.
 */
final class DatabaseImporter
{
    private const CONNECT_TIMEOUT = 10;

    private ?SecretFile $credentials = null;

    public function __construct(
        private readonly Config $config,
        private readonly string $engine,
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
    ) {
        if ($engine === 'mysql' && $config->mysql === null) {
            throw new ConfigException('Importing a MySQL/MariaDB dump needs the MYSQL_* connection settings, and the configuration has none', 'Pass --config with a configuration that has WITH_MYSQL=true, or restore the dump file without --import-as');
        }
        if ($engine === 'pgsql' && $config->pgsql === null) {
            throw new ConfigException('Importing a PostgreSQL dump needs the PGSQL_* connection settings, and the configuration has none', 'Pass --config with a configuration that has WITH_PGSQL=true, or restore the dump file without --import-as');
        }
    }

    /**
     * Letters, digits, "_" and "-": valid unquoted in shell commands and safe to quote in SQL.
     */
    public static function assertValidName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,62}$/', $name) !== 1) {
            throw new UsageException("--import-as '{$name}' is not a database name ArkHive creates: use 1-63 letters, digits, '_' or '-'");
        }
    }

    public function exists(string $name): bool
    {
        $sql = $this->engine === 'mysql'
            ? "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$name}'"
            : "SELECT COUNT(*) FROM pg_database WHERE datname = '{$name}'";

        return trim($this->query($sql, "Checking whether database {$name} exists")) !== '0';
    }

    /**
     * @return bool whether the database was created here (and will be dropped if the import fails)
     *
     * @throws RefusedException the database exists and $force is false
     */
    public function prepare(string $name, bool $force): bool
    {
        self::assertValidName($name);
        if ($this->exists($name)) {
            if (!$force) {
                throw new RefusedException(
                    "The database {$name} already exists: --import-as creates a new database and never imports over an existing one",
                    'Choose another name, or pass --force to import into the existing database',
                );
            }

            return false;
        }
        $this->query($this->engine === 'mysql' ? "CREATE DATABASE `{$name}`" : "CREATE DATABASE \"{$name}\"", "Creating database {$name}");

        return true;
    }

    public function drop(string $name): void
    {
        $this->query($this->engine === 'mysql' ? "DROP DATABASE IF EXISTS `{$name}`" : "DROP DATABASE IF EXISTS \"{$name}\"", "Dropping database {$name}");
    }

    public function import(string $name, string $file): ProcessResult
    {
        if ($this->engine === 'mysql') {
            $command = new Command([
                $this->dependencies->require(Dependency::MysqlClient, 'to import a MySQL/MariaDB dump'),
                '--defaults-extra-file=' . $this->credentials()->path,
                '--connect-timeout=' . self::CONNECT_TIMEOUT,
                '--batch',
                '--',
                $name,
            ], inputFile: $file);
        } else {
            $command = new Command([
                $this->dependencies->require(Dependency::PgClient, 'to import a PostgreSQL dump'),
                ...$this->pgsqlConnection(),
                '--dbname=' . $name,
                '--no-psqlrc',
                '--quiet',
                '--set=ON_ERROR_STOP=1',
                '--file=' . $file,
            ], $this->pgsqlEnvironment());
        }
        $result = $this->runner->run($command);
        if (!$result->succeeded()) {
            throw new ArkhiveException($result->failure("Importing the dump into database {$name}"));
        }

        return $result;
    }

    /**
     * The command an operator runs to import the dump file by hand (no password in it).
     */
    public static function manualCommand(string $engine, string $file, ?Config $config = null): string
    {
        $quoted = escapeshellarg($file);
        if ($engine === 'mysql') {
            $connection = $config?->mysql === null ? '' : sprintf(' -h %s -P %d -u %s -p', escapeshellarg($config->mysql->host), $config->mysql->port, escapeshellarg($config->mysql->user));

            return "mysql{$connection} NEW_DATABASE < {$quoted}";
        }
        $connection = $config?->pgsql === null ? '' : sprintf(' -h %s -p %d -U %s', escapeshellarg($config->pgsql->host), $config->pgsql->port, escapeshellarg($config->pgsql->user));

        return "createdb{$connection} NEW_DATABASE && psql{$connection} --set=ON_ERROR_STOP=1 --dbname=NEW_DATABASE --file={$quoted}";
    }

    public function close(): void
    {
        $this->credentials?->remove();
        $this->credentials = null;
    }

    private function query(string $sql, string $what): string
    {
        if ($this->engine === 'mysql') {
            $command = new Command([
                $this->dependencies->require(Dependency::MysqlClient, 'to import a MySQL/MariaDB dump'),
                '--defaults-extra-file=' . $this->credentials()->path,
                '--connect-timeout=' . self::CONNECT_TIMEOUT,
                '--batch',
                '--skip-column-names',
                '--raw',
                '--execute=' . $sql,
            ]);
            $hint = 'Check MYSQL_HOST, MYSQL_PORT, MYSQL_USER and MYSQL_PASSWORD, and that the user may create databases';
        } else {
            $command = new Command([
                $this->dependencies->require(Dependency::PgClient, 'to import a PostgreSQL dump'),
                ...$this->pgsqlConnection(),
                '--dbname=postgres',
                '--no-psqlrc',
                '--tuples-only',
                '--no-align',
                '--command=' . $sql,
            ], $this->pgsqlEnvironment());
            $hint = 'Check PGSQL_HOST, PGSQL_PORT, PGSQL_USER and PGSQL_PASSWORD, and that the user may create databases (CREATEDB)';
        }
        $result = $this->runner->run($command);
        if (!$result->succeeded()) {
            throw new UnavailableException($result->failure($what), hint: $hint);
        }

        return $result->stdout;
    }

    private function mysql(): MysqlConfig
    {
        assert($this->config->mysql !== null);

        return $this->config->mysql;
    }

    private function pgsql(): PgsqlConfig
    {
        assert($this->config->pgsql !== null);

        return $this->config->pgsql;
    }

    /**
     * @return list<string>
     */
    private function pgsqlConnection(): array
    {
        $pg = $this->pgsql();

        return ['--host=' . $pg->host, '--port=' . $pg->port, '--username=' . $pg->user, '--no-password'];
    }

    /**
     * @return array<string, string|null>
     */
    private function pgsqlEnvironment(): array
    {
        $env = ['PGCONNECT_TIMEOUT' => (string) self::CONNECT_TIMEOUT, 'PGPASSWORD' => null];
        if ($this->pgsql()->password !== null) {
            $env['PGPASSFILE'] = $this->credentials()->path;
        }

        return $env;
    }

    private function credentials(): SecretFile
    {
        if ($this->credentials === null) {
            $file = $this->engine === 'mysql'
                ? SecretFile::create('arkhive-mysql-', MysqlDumper::optionFile($this->mysql()))
                : SecretFile::create('arkhive-pgpass-', PgsqlDumper::passfileLine($this->pgsql()));
            $this->credentials = $file;
            $this->cleanup->add('remove the database credentials file', $file->remove(...));
        }

        return $this->credentials;
    }
}
