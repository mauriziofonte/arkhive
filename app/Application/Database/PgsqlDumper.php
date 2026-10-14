<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\Timeouts;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Backup\DumpResult;
use Mfonte\Arkhive\Domain\Configuration\PgsqlConfig;
use Mfonte\Arkhive\Domain\System\Dependency;
use Mfonte\Arkhive\Domain\System\InstallHints;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * PostgreSQL: every database of PGSQL_DATABASES as a plain-format dump (custom format is compressed
 * and defeats deduplication). The password goes through a temporary PGPASSFILE (0600).
 */
final class PgsqlDumper
{
    private const CONNECT_TIMEOUT = '10';

    private ?SecretFile $passfile = null;
    private ?int $cleanupId = null;

    public function __construct(
        private readonly PgsqlConfig $config,
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
    ) {
    }

    /**
     * A PGPASSFILE line for any database on the configured server; ":" and "\" escaped.
     */
    public static function passfileLine(PgsqlConfig $config): string
    {
        $escape = static fn (string $value): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $value);

        return implode(':', [$escape($config->host), (string) $config->port, '*', $escape($config->user), $escape((string) $config->password)]) . "\n";
    }

    /**
     * @return list<string>
     */
    public function databases(): array
    {
        return $this->config->databases;
    }

    /**
     * Server size of the databases (pg_database_size), for the free-space check.
     *
     * @param list<string> $databases
     */
    public function estimate(array $databases): int
    {
        if ($databases === []) {
            return 0;
        }
        $names = implode(',', array_map(static fn (string $db): string => "'" . str_replace("'", "''", $db) . "'", $databases));

        return (int) trim($this->query("SELECT COALESCE(SUM(pg_database_size(datname)), 0) FROM pg_database WHERE datname IN ({$names})", 'Estimating the PostgreSQL dump size'));
    }

    /**
     * Major versions of pg_dump and of the server; pg_dump refuses to dump a newer server.
     *
     * @return string|null a warning, or null when the client can dump the server
     */
    public function versionWarning(): ?string
    {
        $binary = $this->dependencies->require(Dependency::PgDump, 'to dump PostgreSQL databases (WITH_PGSQL=true)');
        $client = $this->runner->run((new Command([$binary, '--version']))->withTimeout(Timeouts::VERSION));
        $server = (int) trim($this->query("SELECT current_setting('server_version_num')", 'Reading the PostgreSQL server version'));
        if (!$client->succeeded() || preg_match('/(\d+)(?:\.(\d+))?/', $client->stdout, $match) !== 1 || $server === 0) {
            return null;
        }
        $clientMajor = (int) $match[1];
        $serverMajor = intdiv($server, 10000);
        if ($clientMajor >= $serverMajor) {
            return null;
        }

        return "pg_dump {$clientMajor} is older than the PostgreSQL {$serverMajor} server and will refuse to dump it: install the PostgreSQL {$serverMajor} client tools ("
            . InstallHints::forDependency(Dependency::PgDump, $this->dependencies->distro()) . ')';
    }

    public function dump(string $database, DumpStaging $staging): DumpResult
    {
        $binary = $this->dependencies->require(Dependency::PgDump, 'to dump PostgreSQL databases (WITH_PGSQL=true)');
        $path = $staging->pathFor('pgsql', $database);
        $partial = DumpStaging::partial($path);
        $staging->record($path);

        $command = new Command([
            $binary,
            ...$this->connectionOptions(),
            '--format=plain',
            ...$this->config->dumpOptions,
            '--file=' . $partial,
            '--dbname=' . $database,
        ], $this->environment());
        $result = $this->runner->run($command);
        if (!$result->succeeded()) {
            @unlink($partial);
            $hint = str_contains($result->stderr, 'server version mismatch')
                ? 'pg_dump must be the same major version as the server or newer: ' . InstallHints::forDependency(Dependency::PgDump, $this->dependencies->distro())
                : null;

            throw DatabaseErrors::exception($result, "PostgreSQL dump of database {$database}", $hint);
        }
        if (!is_file($partial) || !@rename($partial, $path)) {
            @unlink($partial);

            throw new SoftwareException("PostgreSQL dump of database {$database} reported success but wrote no file at {$partial}");
        }

        return new DumpResult('pgsql', $database, $path, (int) filesize($path), $result->duration);
    }

    public function close(): void
    {
        if ($this->cleanupId !== null) {
            $this->cleanup->runOne($this->cleanupId);
            $this->cleanupId = null;
        }
        $this->passfile = null;
    }

    private function query(string $sql, string $what): string
    {
        $client = $this->dependencies->require(Dependency::PgClient, 'to query PostgreSQL (WITH_PGSQL=true)');
        $result = $this->runner->run((new Command([
            $client,
            ...$this->connectionOptions(),
            '--dbname=' . ($this->config->databases[0] ?? 'postgres'),
            '--no-psqlrc',
            '--tuples-only',
            '--no-align',
            '--command=' . $sql,
        ], $this->environment()))->withTimeout(Timeouts::QUERY));
        if ($result->timedOut) {
            throw DatabaseErrors::exception($result, $what);
        }
        if (!$result->succeeded()) {
            throw new UnavailableException($result->failure($what), hint: 'Check PGSQL_HOST, PGSQL_PORT, PGSQL_USER and PGSQL_PASSWORD, and that the server is running');
        }

        return $result->stdout;
    }

    /**
     * @return list<string>
     */
    private function connectionOptions(): array
    {
        return ['--host=' . $this->config->host, '--port=' . $this->config->port, '--username=' . $this->config->user, '--no-password'];
    }

    /**
     * @return array<string, string|null>
     */
    private function environment(): array
    {
        $env = ['PGCONNECT_TIMEOUT' => self::CONNECT_TIMEOUT, 'PGPASSWORD' => null];
        if ($this->config->password !== null) {
            $env['PGPASSFILE'] = $this->passfile()->path;
        }

        return $env;
    }

    private function passfile(): SecretFile
    {
        if ($this->passfile === null) {
            $file = SecretFile::create('arkhive-pgpass-', self::passfileLine($this->config));
            $this->passfile = $file;
            $this->cleanupId = $this->cleanup->add('remove the PostgreSQL password file', $file->remove(...));
        }

        return $this->passfile;
    }
}
