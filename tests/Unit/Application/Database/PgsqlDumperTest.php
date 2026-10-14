<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Database;

use Mfonte\Arkhive\Application\Database\DumpStaging;
use Mfonte\Arkhive\Application\Database\PgsqlDumper;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Application\Process\Timeouts;
use Mfonte\Arkhive\Domain\Configuration\PgsqlConfig;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\TempDir;

final class PgsqlDumperTest extends TestCase
{
    use TempDir;

    private const PASSWORD = 'pa:ss\\w"rd#1';

    private FakeProcessRunner $runner;
    private Cleanup $cleanup;
    private string $bin;
    private DumpStaging $staging;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->cleanup = new Cleanup();
        $this->bin = $this->tempDir() . '/bin';
        $this->staging = new DumpStaging($this->tempDir() . '/dumps');
        $this->staging->prepare();
    }

    /**
     * @param list<string> $databases
     */
    private function dumper(array $databases = ['inventory', 'crm'], ?string $password = self::PASSWORD, string $osRelease = "ID=debian\n"): PgsqlDumper
    {
        return new PgsqlDumper(
            new PgsqlConfig('pg.example.com', 5433, 'backup', $password, $databases, ['--no-owner', '--no-privileges']),
            $this->runner,
            FakeBinaries::dependencies($this->bin, ['pg_dump', 'psql'], $osRelease),
            $this->cleanup,
        );
    }

    private static function writeDump(Command $command, string $content = "-- PostgreSQL dump\n"): void
    {
        foreach ($command->argv as $arg) {
            if (str_starts_with($arg, '--file=')) {
                file_put_contents(substr($arg, strlen('--file=')), $content);
            }
        }
    }

    public function testPassfileLineEscapesColonsAndBackslashes(): void
    {
        self::assertSame(
            "pg.example.com:5433:*:backup:pa\\:ss\\\\w\"rd#1\n",
            PgsqlDumper::passfileLine(new PgsqlConfig('pg.example.com', 5433, 'backup', self::PASSWORD, ['x'], [])),
        );
        self::assertSame("/run/postgresql:5432:*:u\\:x:\n", PgsqlDumper::passfileLine(new PgsqlConfig('/run/postgresql', 5432, 'u:x', null, ['x'], [])));
    }

    public function testEveryDatabaseIsDumpedInPlainFormatWithThePasswordInAPassfile(): void
    {
        $seen = [];
        $this->runner->on('pg_dump', static function (Command $command) use (&$seen): int {
            $passfile = $command->env['PGPASSFILE'] ?? '';
            $seen[] = [file_get_contents($passfile), fileperms($passfile) & 0777];
            self::writeDump($command);

            return 0;
        });

        $dumper = $this->dumper();
        self::assertSame(['inventory', 'crm'], $dumper->databases());
        $first = $dumper->dump('inventory', $this->staging);
        $second = $dumper->dump('crm', $this->staging);

        $command = $this->runner->commands[0];
        self::assertSame([
            "{$this->bin}/pg_dump",
            '--host=pg.example.com', '--port=5433', '--username=backup', '--no-password',
            '--format=plain', '--no-owner', '--no-privileges',
            '--file=' . $this->staging->directory . '/pgsql/inventory.sql.partial',
            '--dbname=inventory',
        ], $command->argv);
        self::assertSame('10', $command->env['PGCONNECT_TIMEOUT']);
        self::assertArrayHasKey('PGPASSWORD', $command->env);
        self::assertNull($command->env['PGPASSWORD'], 'an inherited PGPASSWORD is never used');
        self::assertSame([["pg.example.com:5433:*:backup:pa\\:ss\\\\w\"rd#1\n", 0600], ["pg.example.com:5433:*:backup:pa\\:ss\\\\w\"rd#1\n", 0600]], $seen);
        self::assertSame($this->staging->directory . '/pgsql/inventory.sql', $first->path);
        self::assertSame('pgsql', $second->engine);
        self::assertFileExists($second->path);
        self::assertStringNotContainsString('pa:ss', $this->runner->everythingOnTheCommandLine());

        $passfile = $command->env['PGPASSFILE'];
        self::assertIsString($passfile);
        $dumper->close();
        self::assertFileDoesNotExist($passfile);
        $dumper->close();
    }

    public function testNoPasswordLeavesAuthenticationToPgpassOrPeer(): void
    {
        $this->runner->on('pg_dump', static function (Command $command): int {
            self::writeDump($command);

            return 0;
        });

        $this->dumper(password: null)->dump('inventory', $this->staging);

        self::assertArrayNotHasKey('PGPASSFILE', $this->runner->commands[0]->env);
    }

    public function testAFailedDumpRemovesThePartialFile(): void
    {
        $this->runner->on('pg_dump', static function (Command $command): array {
            self::writeDump($command, 'half');

            return [1, '', "pg_dump: error: connection to server at \"pg.example.com\", port 5433 failed: FATAL:  password authentication failed for user \"backup\"\n"];
        });

        try {
            $this->dumper()->dump('inventory', $this->staging);
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('PostgreSQL dump of database inventory failed (pg_dump exit 1): pg_dump: error: connection to server at "pg.example.com", port 5433 failed: FATAL:  password authentication failed for user "backup"', $e->getMessage());
            self::assertSame(ExitCode::NO_PERMISSION, $e->exitCode());
            self::assertNotNull($e->hint());
        }
        self::assertSame([], glob($this->staging->directory . '/pgsql/*') ?: []);
    }

    public function testVersionMismatchGetsTheInstallHint(): void
    {
        $this->runner->on('pg_dump', static fn (): array => [1, '', "pg_dump: error: aborting because of server version mismatch\npg_dump: detail: server version: 17.2; pg_dump version: 15.10\n"]);

        try {
            $this->dumper(osRelease: "ID=alpine\n")->dump('inventory', $this->staging);
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('pg_dump must be the same major version as the server or newer: Run: apk add postgresql-client', $e->hint());
        }
    }

    public function testSuccessWithoutAFileIsAnError(): void
    {
        $this->runner->on('pg_dump', static fn (): int => 0);

        $this->expectExceptionObject(new ArkhiveException('PostgreSQL dump of database crm reported success but wrote no file at ' . $this->staging->directory . '/pgsql/crm.sql.partial'));
        $this->dumper()->dump('crm', $this->staging);
    }

    public function testAnOlderPgDumpIsAWarning(): void
    {
        $this->runner
            ->on('pg_dump', static fn (): array => [0, "pg_dump (PostgreSQL) 15.10 (Debian 15.10-0+deb12u1)\n", ''])
            ->on('psql', static fn (): array => [0, "170002\n", '']);

        self::assertSame(
            'pg_dump 15 is older than the PostgreSQL 17 server and will refuse to dump it: install the PostgreSQL 17 client tools (Run: apt install postgresql-client)',
            $this->dumper()->versionWarning(),
        );
        self::assertSame([
            "{$this->bin}/psql",
            '--host=pg.example.com', '--port=5433', '--username=backup', '--no-password',
            '--dbname=inventory', '--no-psqlrc', '--tuples-only', '--no-align',
            "--command=SELECT current_setting('server_version_num')",
        ], $this->runner->commands[1]->argv);
    }

    public function testSameOrNewerClientIsFine(): void
    {
        $this->runner
            ->on('pg_dump', static fn (): array => [0, "pg_dump (PostgreSQL) 16.15\n", ''])
            ->on('psql', static fn (): array => [0, "160004\n", '']);
        self::assertNull($this->dumper()->versionWarning());

        $this->runner->on('pg_dump', static fn (): array => [0, "pg_dump (PostgreSQL) 18beta1\n", '']);
        self::assertNull($this->dumper()->versionWarning());

        $this->runner->on('pg_dump', static fn (): array => [127, '', 'broken']);
        self::assertNull($this->dumper()->versionWarning());
    }

    public function testQueryFailureNamesTheSettings(): void
    {
        $this->runner
            ->on('pg_dump', static fn (): array => [0, "pg_dump (PostgreSQL) 16.1\n", ''])
            ->on('psql', static fn (): array => [2, '', "psql: error: connection to server at \"pg.example.com\" (192.0.2.1), port 5433 failed: timeout expired\n"]);

        try {
            $this->dumper()->versionWarning();
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('Reading the PostgreSQL server version failed (psql exit 2): psql: error: connection to server at "pg.example.com" (192.0.2.1), port 5433 failed: timeout expired', $e->getMessage());
            self::assertSame('Check PGSQL_HOST, PGSQL_PORT, PGSQL_USER and PGSQL_PASSWORD, and that the server is running', $e->hint());
        }
    }

    public function testQueriesAndTheVersionHaveDeadlines(): void
    {
        $this->runner
            ->on('pg_dump', static fn (): array => [0, "pg_dump (PostgreSQL) 16.1\n", ''])
            ->on('psql', static fn (Command $command): ProcessResult => new ProcessResult($command, 143, '', '', 300.0, true));

        try {
            $this->dumper()->versionWarning();
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame(ExitCode::TEMPORARY, $e->exitCode());
            self::assertSame('Reading the PostgreSQL server version timed out after 300s (psql)', $e->getMessage());
        }
        self::assertSame([Timeouts::VERSION, Timeouts::QUERY], array_map(static fn (Command $command): ?float => $command->timeout, $this->runner->commands));
    }

    public function testEstimateSumsDatabaseSizes(): void
    {
        $this->runner->on('psql', static fn (): array => [0, "987654\n", '']);

        self::assertSame(987654, $this->dumper()->estimate(['inventory', "o'neil"]));
        self::assertSame("--command=SELECT COALESCE(SUM(pg_database_size(datname)), 0) FROM pg_database WHERE datname IN ('inventory','o''neil')", $this->runner->commands[0]->argv[9]);
        self::assertSame(0, $this->dumper()->estimate([]));
    }
}
