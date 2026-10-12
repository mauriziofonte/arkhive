<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dump;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Config\ConfigFactory;
use Mfonte\Arkhive\Services\Context\ExecutionContext;
use Mfonte\Arkhive\Services\Context\LogFormat;
use Mfonte\Arkhive\Services\Dump\DatabaseDumps;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Output\LogStream;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Command;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;

final class DatabaseDumpsTest extends TestCase
{
    use TempDir;

    private FakeProcessRunner $runner;
    private Cleanup $cleanup;
    private LogStream $stream;
    private Logger $log;
    private string $dumpDir;
    private DatabaseDumps $dumps;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->cleanup = new Cleanup();
        $this->stream = LogStream::memory();
        $this->log = new Logger(new ExecutionContext(false, false, false, LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock());
        $root = $this->tempDir();
        $this->dumpDir = "{$root}/var/dumps";
        $this->dumps = new DatabaseDumps($this->runner, FakeBinaries::dependencies("{$root}/bin", ['mariadb-dump', 'mariadb', 'pg_dump', 'psql']), $this->cleanup);
    }

    /**
     * @param array<string, string> $values
     */
    private function config(array $values = []): Config
    {
        return ConfigFactory::create([
            'BACKUP_PATHS' => '/srv/www',
            'RESTIC_REPOSITORY' => '/srv/restic-repo',
            'RESTIC_PASSWORD_FILE' => '/etc/arkhive/restic-password',
            'DUMP_DIRECTORY' => $this->dumpDir,
            'WITH_MYSQL' => 'true',
            'MYSQL_USER' => 'backup',
            'MYSQL_PASSWORD' => 'mysql-secret-placeholder',
            'MYSQL_DATABASES' => '*',
            'WITH_PGSQL' => 'true',
            'PGSQL_USER' => 'backup',
            'PGSQL_PASSWORD' => 'pg-secret-placeholder',
            'PGSQL_DATABASES' => 'inventory crm',
            ...$values,
        ], '/etc/arkhive/config', 'host.example.com', false);
    }

    private function servers(string $mysqlSize = '1000', string $pgSize = '2000'): void
    {
        $this->runner
            ->on('mariadb', static fn (Command $c): array => str_contains($c->argv[6], 'SHOW DATABASES')
                ? [0, "information_schema\nshop\nblog\nmysql\n", '']
                : [0, "{$mysqlSize}\n", ''])
            ->on('psql', static fn (Command $c): array => str_contains($c->argv[9], 'server_version_num') ? [0, "160004\n", ''] : [0, "{$pgSize}\n", ''])
            ->on('mariadb-dump', static function (Command $c): int {
                file_put_contents(substr($c->argv[8], strlen('--result-file=')), str_repeat('m', 100));

                return 0;
            })
            ->on('pg_dump', static function (Command $c): array|int {
                if ($c->argv[1] === '--version') {
                    return [0, "pg_dump (PostgreSQL) 16.4\n", ''];
                }
                file_put_contents(substr($c->argv[8], strlen('--file=')), str_repeat('p', 200));

                return 0;
            });
    }

    public function testEveryDatabaseIsDumpedThenClearedOnRequest(): void
    {
        $this->servers();

        $run = $this->dumps->run($this->config(), $this->log);

        self::assertNotNull($run);
        self::assertSame(
            ["{$this->dumpDir}/mysql/shop.sql", "{$this->dumpDir}/mysql/blog.sql", "{$this->dumpDir}/pgsql/inventory.sql", "{$this->dumpDir}/pgsql/crm.sql"],
            array_map(static fn ($r): string => $r->path, $run->results),
        );
        self::assertSame(600, $run->totalBytes());
        self::assertSame($run->staging->written(), array_map(static fn ($r): string => $r->path, $run->results));
        self::assertSame(0700, fileperms($this->dumpDir) & 0777);
        self::assertSame(
            "2026-10-12T10:20:30+02:00 info Dumping MySQL database shop\n"
            . "2026-10-12T10:20:30+02:00 success Dumped MySQL database shop: 100 B in 0.2s\n"
            . "2026-10-12T10:20:30+02:00 info Dumping MySQL database blog\n"
            . "2026-10-12T10:20:30+02:00 success Dumped MySQL database blog: 100 B in 0.2s\n"
            . "2026-10-12T10:20:30+02:00 info Dumping PostgreSQL database inventory\n"
            . "2026-10-12T10:20:30+02:00 success Dumped PostgreSQL database inventory: 200 B in 0.2s\n"
            . "2026-10-12T10:20:30+02:00 info Dumping PostgreSQL database crm\n"
            . "2026-10-12T10:20:30+02:00 success Dumped PostgreSQL database crm: 200 B in 0.2s\n",
            $this->stream->contents(),
        );
        self::assertSame(1, $this->cleanup->pending(), 'only the dump directory task remains: credentials are already gone');
        foreach ($this->runner->commands as $command) {
            foreach ($command->argv as $arg) {
                if (str_starts_with($arg, '--defaults-extra-file=')) {
                    self::assertFileDoesNotExist(substr($arg, 22));
                }
            }
            if (isset($command->env['PGPASSFILE'])) {
                self::assertFileDoesNotExist($command->env['PGPASSFILE']);
            }
        }
        self::assertStringNotContainsString('secret-placeholder', $this->runner->everythingOnTheCommandLine());

        $this->dumps->clear($run);
        self::assertSame([], glob("{$this->dumpDir}/*/*") ?: []);
        self::assertSame(0, $this->cleanup->pending());
    }

    public function testNoDumpsConfiguredDoesNothing(): void
    {
        self::assertNull($this->dumps->run($this->config(['WITH_MYSQL' => 'false', 'WITH_PGSQL' => 'false', 'DUMP_DIRECTORY' => '']), $this->log));
        self::assertSame([], $this->runner->commands);
        self::assertDirectoryDoesNotExist($this->dumpDir);
    }

    public function testADumpFailureRemovesTheDumpsAlreadyWritten(): void
    {
        $this->servers();
        $this->runner->on('pg_dump', static function (Command $c): array {
            if ($c->argv[1] === '--version') {
                return [0, "pg_dump (PostgreSQL) 16.4\n", ''];
            }

            return [1, '', "pg_dump: error: database \"inventory\" does not exist\n"];
        });

        try {
            $this->dumps->run($this->config(), $this->log);
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('PostgreSQL dump of database inventory failed (pg_dump exit 1): pg_dump: error: database "inventory" does not exist', $e->getMessage());
        }
        self::assertSame([], glob("{$this->dumpDir}/*/*") ?: [], 'the MySQL dumps written before the failure are gone');
        self::assertSame(0, $this->cleanup->pending());
    }

    public function testAnInterruptionRemovesTheDumps(): void
    {
        $this->servers();
        $this->runner->on('pg_dump', static fn (): never => throw new InterruptedException(InterruptedException::SIGTERM));

        try {
            $this->dumps->run($this->config(), $this->log);
            self::fail('expected an interruption');
        } catch (InterruptedException $e) {
            self::assertSame(143, $e->exitCode());
        }
        self::assertSame([], glob("{$this->dumpDir}/*/*") ?: []);
    }

    public function testNotEnoughSpaceIsRefusedBeforeAnyDump(): void
    {
        $this->servers(mysqlSize: (string) PHP_INT_MAX);

        try {
            $this->dumps->run($this->config(), $this->log);
            self::fail('expected a refusal');
        } catch (RefusedException $e) {
            self::assertSame(4, $e->exitCode());
            self::assertMatchesRegularExpression('#^Not enough free space for the database dumps in ' . preg_quote($this->dumpDir, '#') . ': about 8192\.00 PiB needed, [0-9.]+ (B|KiB|MiB|GiB|TiB|PiB) available$#', $e->getMessage());
            self::assertSame('Free some space, or point DUMP_DIRECTORY to a larger filesystem', $e->hint());
        }
        foreach ($this->runner->commands as $command) {
            self::assertNotContains($command->name(), ['mariadb-dump'], 'no dump was started');
        }
    }

    public function testAnEstimateFailureSkipsTheSpaceCheckWithAWarning(): void
    {
        $this->servers();
        $this->runner->on('psql', static fn (Command $c): array => str_contains($c->argv[9], 'server_version_num') ? [0, "160004\n", ''] : [1, '', "ERROR:  permission denied for function pg_database_size\n"]);

        $run = $this->dumps->run($this->config(), $this->log);

        self::assertNotNull($run);
        self::assertCount(4, $run->results);
        self::assertStringContainsString(
            'warning Could not estimate the size of the PostgreSQL dumps, skipping the free-space check: Estimating the PostgreSQL dump size failed (psql exit 1): ERROR:  permission denied for function pg_database_size',
            $this->stream->contents(),
        );
    }

    public function testLeftoversOfAKilledRunAreRemovedWithAWarning(): void
    {
        mkdir("{$this->dumpDir}/mysql", 0700, true);
        file_put_contents("{$this->dumpDir}/mysql/dropped_db.sql", 'stale');
        file_put_contents("{$this->dumpDir}/mysql/shop.sql.partial", 'stale');
        file_put_contents("{$this->dumpDir}/README", 'not ours');
        $this->servers();

        $this->dumps->run($this->config(['WITH_PGSQL' => 'false']), $this->log);

        self::assertStringStartsWith("2026-10-12T10:20:30+02:00 warning Removed 2 dump file(s) left in {$this->dumpDir} by a run that did not finish\n", $this->stream->contents());
        self::assertFileDoesNotExist("{$this->dumpDir}/mysql/dropped_db.sql");
        self::assertFileExists("{$this->dumpDir}/README");
    }

    public function testAnOldPgDumpAndAnEmptyServerAreWarnings(): void
    {
        $this->servers();
        $this->runner
            ->on('mariadb', static fn (Command $c): array => str_contains($c->argv[6], 'SHOW DATABASES') ? [0, "information_schema\nmysql\nsys\n", ''] : [0, "0\n", ''])
            ->on('psql', static fn (Command $c): array => str_contains($c->argv[9], 'server_version_num') ? [0, "170001\n", ''] : [0, "10\n", '']);

        $this->dumps->run($this->config(), $this->log);

        $log = $this->stream->contents();
        self::assertStringContainsString('warning pg_dump 16 is older than the PostgreSQL 17 server', $log);
        self::assertStringContainsString('warning No MySQL database to dump: the server has none besides the system schemas', $log);
    }
}
