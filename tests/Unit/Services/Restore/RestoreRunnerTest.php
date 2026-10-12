<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Restore;

use DateTimeImmutable;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Config\ResticSettings;
use Mfonte\Arkhive\Services\Context\ExecutionContext;
use Mfonte\Arkhive\Services\Context\LogFormat;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Output\LogStream;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Command;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restic\Snapshot;
use Mfonte\Arkhive\Services\Restore\DumpFile;
use Mfonte\Arkhive\Services\Restore\OwnerMode;
use Mfonte\Arkhive\Services\Restore\RestorePlan;
use Mfonte\Arkhive\Services\Restore\RestoreRunner;
use Mfonte\Arkhive\Services\Restore\Selection;
use Mfonte\Arkhive\Services\System\Lock;
use PHPUnit\Framework\TestCase;
use Tests\Support\Configs;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;

final class RestoreRunnerTest extends TestCase
{
    use TempDir;

    private FakeProcessRunner $runner;
    private FakeRestic $fake;
    private Restic $restic;
    private Cleanup $cleanup;
    private LogStream $stream;
    private string $root;
    private Snapshot $snapshot;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->fake = (new FakeRestic())->withRepository()->install($this->runner);
        $this->restic = new Restic(new ResticSettings('/srv/restic-repo', '/etc/arkhive/restic-password'), '/usr/bin/restic', $this->runner);
        $this->cleanup = new Cleanup();
        $this->stream = LogStream::memory();
        $this->root = $this->tempDir();
        mkdir("{$this->root}/locks");
        $this->snapshot = new Snapshot('6118b3c5b2b1ffc9e12d6022dfcab6275e9621122cb2b8d5b66d11a67fdc5c59', new DateTimeImmutable('2026-10-12T14:14:37Z'), 'web1.example.com', ['/srv/dumps', '/srv/www']);
    }

    private function runner(int $uid = 0): RestoreRunner
    {
        return new RestoreRunner(
            $this->runner,
            FakeBinaries::dependencies("{$this->root}/bin", ['restic', 'mariadb', 'psql']),
            $this->cleanup,
            new FixedClock(),
            ['ARKHIVE_LOCK_DIR' => "{$this->root}/locks"],
            $uid,
            $uid,
        );
    }

    private function log(): Logger
    {
        return new Logger(new ExecutionContext(false, false, false, LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock());
    }

    private function plan(Selection $selection, ?string $target, OwnerMode $owner = OwnerMode::Preserve, bool $inPlace = false, bool $force = false, ?Snapshot $snapshot = null): RestorePlan
    {
        return new RestorePlan($snapshot ?? $this->snapshot, $selection, $target, $owner, $inPlace, $force);
    }

    /**
     * @param array<mixed> $list
     */
    private static function last(array $list): mixed
    {
        return $list[array_key_last($list)] ?? null;
    }

    /**
     * @return resource
     */
    private static function sink()
    {
        $sink = fopen('php://memory', 'w+b');
        self::assertIsResource($sink);

        return $sink;
    }

    public function testFullRestoreIntoANewDirectoryIsVerified(): void
    {
        $target = "{$this->root}/restore";
        $plan = $this->plan(Selection::everything(), $target);

        self::assertSame(82, $this->runner()->preflight($this->restic, $plan, null));
        $report = $this->runner()->run($this->restic, $plan, null, 'web1.example.com', $this->log(), self::sink());

        self::assertSame(['restore', '--json', '--verify', '--target', $target, '--', $this->snapshot->id], $this->fake->callsOf('restore')[0]);
        self::assertFileExists("{$target}/srv/www/site/index.php");
        self::assertTrue($report->verified);
        self::assertSame(14, $report->files);
        self::assertSame(82, $report->bytes);
        self::assertNull($report->safetySnapshot);
        self::assertSame([], $report->warnings);
        self::assertSame([
            '/srv/dumps holds database dumps: import one into a new database with: arkhive restore --snapshot 6118b3c5 --db <name> --import-as <new name>',
            "Review, then put it in place with: rsync -aHAX --numeric-ids '{$target}/srv/www/' '/srv/www/'",
            'or restore in place (a safety snapshot is taken first): arkhive restore --snapshot 6118b3c5 --in-place --force',
        ], $report->nextSteps);
        self::assertSame(0, $this->cleanup->pending(), 'nothing left to clean up after a successful restore');
        self::assertStringContainsString("success Restored and verified 14 files and directories (82 B) in {$target}", $this->stream->contents());
        self::assertSame('everything', $report->toArray()['selection']);
    }

    public function testIncludesAndExcludesArePassedToRestic(): void
    {
        $target = "{$this->root}/restore";
        $report = $this->runner()->run($this->restic, $this->plan(Selection::paths(['/srv/www/site', '/srv/www/with space'], ['*.css']), $target), null, 'h', $this->log(), self::sink());

        self::assertSame(['restore', '--json', '--verify', '--target', $target, '--include', '/srv/www/site', '--include', '/srv/www/with space', '--exclude', '*.css', '--', $this->snapshot->id], $this->fake->callsOf('restore')[0]);
        self::assertSame("Review, then put it in place with: rsync -aHAX --numeric-ids '{$target}/srv/www/site/' '/srv/www/site/'", $report->nextSteps[0]);
        self::assertSame("or restore in place (a safety snapshot is taken first): arkhive restore --snapshot 6118b3c5 --include '/srv/www/site' --include '/srv/www/with space' --in-place --force", self::last($report->nextSteps));
    }

    public function testAFailedRestoreRemovesTheTargetItCreated(): void
    {
        $target = "{$this->root}/restore";
        $this->fake->on['restore'] = static function () use ($target): array {
            mkdir("{$target}/srv", 0755, true);
            file_put_contents("{$target}/srv/partial", 'x');

            return [1, '', "Fatal: There were 1 errors\n"];
        };

        try {
            $this->runner()->run($this->restic, $this->plan(Selection::everything(), $target), null, 'h', $this->log(), self::sink());
            self::fail('expected the restore to fail');
        } catch (ArkhiveException $e) {
            self::assertSame('restic restore failed (restic exit 1): Fatal: There were 1 errors', $e->getMessage());
        }
        self::assertSame([], $this->cleanup->runAll());
        self::assertFileDoesNotExist($target);
    }

    public function testAFailedRestoreLeavesAnExistingTargetAlone(): void
    {
        $target = "{$this->root}/existing";
        mkdir($target);
        $this->fake->on['restore'] = static fn (): array => [1, '', "Fatal: boom\n"];

        try {
            $this->runner()->run($this->restic, $this->plan(Selection::everything(), $target), null, 'h', $this->log(), self::sink());
            self::fail('expected the restore to fail');
        } catch (ArkhiveException) {
        }
        $this->cleanup->runAll();
        self::assertDirectoryExists($target);
    }

    public function testPreflightRefusesANonEmptyTargetAndMissingSpace(): void
    {
        mkdir("{$this->root}/full");
        touch("{$this->root}/full/f");
        try {
            $this->runner()->preflight($this->restic, $this->plan(Selection::everything(), "{$this->root}/full"), null);
            self::fail('expected a refusal');
        } catch (RefusedException $e) {
            self::assertStringContainsString('is not empty', $e->getMessage());
        }
        self::assertSame(82, $this->runner()->preflight($this->restic, $this->plan(Selection::everything(), "{$this->root}/full", force: true), null));

        $this->fake->on['stats'] = static fn (): array => [0, '{"total_size":' . PHP_INT_MAX . ',"total_file_count":1,"snapshots_count":1}', ''];
        $this->expectException(RefusedException::class);
        $this->expectExceptionMessageMatches('/^Not enough free space in .+ for the restore: 8192\.00 PiB needed, .+ available$/');
        $this->runner()->preflight($this->restic, $this->plan(Selection::everything(), "{$this->root}/new"), null);
    }

    public function testInPlaceTakesTheLockAndASafetySnapshotFirst(): void
    {
        $original = "{$this->root}/srv/www";
        mkdir($original, 0755, true);
        file_put_contents("{$original}/index.php", 'current');
        $snapshot = new Snapshot('aaaa1111', new DateTimeImmutable(), 'web1.example.com', [$original, "{$this->root}/srv/never-existed"]);
        $order = [];
        $lockPath = Lock::forRepository('/srv/restic-repo', ['ARKHIVE_LOCK_DIR' => "{$this->root}/locks"], new FixedClock(), 0)->path();
        $this->fake->on['backup'] = function (array $args) use (&$order, $lockPath): array {
            $order[] = 'backup';
            self::assertStringContainsString('restore --in-place', (string) file_get_contents($lockPath), 'the lock is held');

            return [0, FakeRestic::fixture('backup-0.19-first.jsonl'), ''];
        };
        $this->fake->on['restore'] = static function () use (&$order): array {
            $order[] = 'restore';

            return [0, FakeRestic::fixture('restore-0.19.jsonl'), ''];
        };
        $plan = $this->plan(Selection::everything(), '/', inPlace: true, force: true, snapshot: $snapshot);

        $this->runner()->preflight($this->restic, $plan, null);
        $report = $this->runner()->run($this->restic, $plan, null, 'web1.example.com', $this->log(), self::sink());

        self::assertSame(['backup', 'restore'], $order);
        self::assertSame(['backup', '--json', '--tag', 'pre-restore', '--host', 'web1.example.com', '--', $original], $this->fake->callsOf('backup')[0], 'only paths that exist');
        self::assertSame(['restore', '--json', '--verify', '--target', '/', '--', 'aaaa1111'], $this->fake->callsOf('restore')[0]);
        self::assertSame('e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', $report->safetySnapshot);
        self::assertSame(['Undo this restore with: arkhive restore --snapshot e8ecb7f4 --in-place --force'], $report->nextSteps);
        self::assertStringContainsString('success Safety snapshot e8ecb7f4 saved (tag pre-restore); undo the restore with: arkhive restore --snapshot e8ecb7f4 --in-place --force', $this->stream->contents());
        self::assertSame([], $this->cleanup->runAll());
        self::assertFalse(Lock::forRepository('/srv/restic-repo', ['ARKHIVE_LOCK_DIR' => "{$this->root}/locks"], new FixedClock(), 0)->held(), 'released');
    }

    public function testInPlaceSafetySnapshotCoversTheIncludedPathsOnly(): void
    {
        mkdir("{$this->root}/srv/www/site", 0755, true);
        $snapshot = new Snapshot('aaaa1111', new DateTimeImmutable(), 'h', ["{$this->root}/srv/www"]);
        $this->fake->on['restore'] = static fn (): array => [0, '', ''];

        $this->runner()->run($this->restic, $this->plan(Selection::paths(["{$this->root}/srv/www/site/"]), '/', inPlace: true, force: true, snapshot: $snapshot), null, 'h', $this->log(), self::sink());

        self::assertSame(['backup', '--json', '--tag', 'pre-restore', '--host', 'h', '--', "{$this->root}/srv/www/site"], $this->fake->callsOf('backup')[0]);
    }

    public function testInPlaceWithPatternsProtectsTheWholeSnapshotPaths(): void
    {
        mkdir("{$this->root}/srv/www", 0755, true);
        $snapshot = new Snapshot('aaaa1111', new DateTimeImmutable(), 'h', ["{$this->root}/srv/www"]);
        $this->fake->on['restore'] = static fn (): array => [0, '', ''];

        $this->runner()->run($this->restic, $this->plan(Selection::paths(['*.php']), '/', inPlace: true, force: true, snapshot: $snapshot), null, 'h', $this->log(), self::sink());

        self::assertSame("{$this->root}/srv/www", self::last($this->fake->callsOf('backup')[0]));
    }

    public function testInPlaceWithNothingAtTheOriginalPathsNeedsNoSafetySnapshot(): void
    {
        $snapshot = new Snapshot('aaaa1111', new DateTimeImmutable(), 'h', ["{$this->root}/gone"]);
        $this->fake->on['restore'] = static fn (): array => [0, '', ''];

        $report = $this->runner()->run($this->restic, $this->plan(Selection::everything(), '/', inPlace: true, force: true, snapshot: $snapshot), null, 'h', $this->log(), self::sink());

        self::assertSame([], $this->fake->callsOf('backup'));
        self::assertNull($report->safetySnapshot);
        self::assertSame([], $report->nextSteps);
        self::assertStringContainsString('no safety snapshot is needed', $this->stream->contents());
    }

    public function testAFailedSafetySnapshotRestoresNothing(): void
    {
        mkdir("{$this->root}/www");
        $snapshot = new Snapshot('aaaa1111', new DateTimeImmutable(), 'h', ["{$this->root}/www"]);
        $this->fake->on['backup'] = static fn (): array => [3, FakeRestic::fixture('backup-0.19-incomplete.jsonl'), FakeRestic::fixture('backup-0.19-incomplete.stderr')];

        try {
            $this->runner()->run($this->restic, $this->plan(Selection::everything(), '/', inPlace: true, force: true, snapshot: $snapshot), null, 'h', $this->log(), self::sink());
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertStringStartsWith('The safety snapshot before the in-place restore (nothing was restored) failed (restic exit 3)', $e->getMessage());
        }
        self::assertSame([], $this->fake->callsOf('restore'));
        $this->cleanup->runAll();
    }

    public function testInPlaceSpaceCountsWhatIsOverwritten(): void
    {
        mkdir("{$this->root}/www");
        file_put_contents("{$this->root}/www/big", str_repeat('x', 4096));
        $snapshot = new Snapshot('aaaa1111', new DateTimeImmutable(), 'h', ["{$this->root}/www"]);
        $this->fake->on['stats'] = static fn (): array => [0, '{"total_size":4000}', ''];

        self::assertSame(4000, $this->runner()->preflight($this->restic, $this->plan(Selection::everything(), '/', inPlace: true, force: true, snapshot: $snapshot), null));
    }

    public function testOwnerCurrentAsNonRootWarns(): void
    {
        $report = $this->runner(1000)->run($this->restic, $this->plan(Selection::paths(['/srv/www/site']), "{$this->root}/r", OwnerMode::Current), null, 'h', $this->log(), self::sink());

        self::assertSame(['Ownership was not restored: running as uid 1000, the restored files belong to you (run as root to restore the original owners)'], $report->warnings);
        self::assertStringContainsString('warning Ownership was not restored', $this->stream->contents());
    }

    public function testOwnerCurrentAsRootGivesTheFilesToRoot(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('changing owners needs root');
        }
        $target = "{$this->root}/r";
        $inner = $this->fake->on['restore'];
        $this->fake->on['restore'] = static function (array $args, Command $command) use ($inner, $target): array {
            $result = $inner($args, $command);
            chown("{$target}/srv/www/site/index.php", 4321);

            return $result;
        };

        $report = $this->runner(0)->run($this->restic, $this->plan(Selection::paths(['/srv/www/site']), $target, OwnerMode::Current), null, 'h', $this->log(), self::sink());

        clearstatcache();
        self::assertSame(0, fileowner("{$target}/srv/www/site/index.php"));
        self::assertSame([], $report->warnings);
    }

    public function testASingleFileToStdout(): void
    {
        $sink = self::sink();
        $plan = $this->plan(Selection::stdout('/srv/www/site/index.php'), null);

        self::assertSame(22, $this->runner()->preflight($this->restic, $plan, null));
        $report = $this->runner()->run($this->restic, $plan, null, 'h', $this->log(), $sink);

        rewind($sink);
        self::assertSame("dump of /srv/www/site/index.php\n", stream_get_contents($sink));
        self::assertSame(['ls', '--json', '--', $this->snapshot->id, '/srv/www/site'], $this->fake->callsOf('ls')[0]);
        self::assertSame(1, $report->files);
        self::assertSame(22, $report->bytes);
        self::assertFalse($report->verified);
    }

    public function testStdoutNeedsAnAbsolutePathToAFileInTheSnapshot(): void
    {
        foreach ([
            'site/index.php' => "--to-stdout needs the absolute path of the file in the snapshot, not 'site/index.php'",
            '/srv/www/missing.php' => '/srv/www/missing.php is not in snapshot 6118b3c5',
            '/srv/www/site' => '/srv/www/site is a dir, not a file: --to-stdout writes a single file',
        ] as $file => $message) {
            try {
                $this->runner()->run($this->restic, $this->plan(Selection::stdout($file), null), null, 'h', $this->log(), self::sink());
                self::fail("{$file} should be refused");
            } catch (UsageException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->fake->callsOf('dump'));
    }

    private function mysqlServer(bool $exists = false, int $importExit = 0): void
    {
        $this->runner->on('mariadb', function (Command $command) use ($exists, $importExit): array {
            $credentials = substr($command->argv[1], strlen('--defaults-extra-file='));
            self::assertStringContainsString('password="mysql-password-placeholder"', (string) file_get_contents($credentials));
            foreach ($command->argv as $arg) {
                if (str_starts_with($arg, '--execute=SELECT COUNT(*)')) {
                    return [0, $exists ? "1\n" : "0\n", ''];
                }
                if (str_starts_with($arg, '--execute=')) {
                    return [0, '', ''];
                }
            }
            self::assertNotNull($command->inputFile);
            self::assertSame("dump of /srv/dumps/mysql/shop.sql\n", file_get_contents($command->inputFile));

            return [$importExit, '', $importExit === 0 ? '' : "ERROR 1064 (42000) at line 1: syntax error\n"];
        });
    }

    /**
     * @return list<string> the SQL run and the imports, in order
     */
    private function mysqlLog(): array
    {
        $log = [];
        foreach ($this->runner->commands as $command) {
            if ($command->name() !== 'mariadb') {
                continue;
            }
            $sql = array_values(array_filter($command->argv, static fn (string $a): bool => str_starts_with($a, '--execute=')));
            $log[] = $sql === [] ? 'import ' . self::last($command->argv) : substr($sql[0], strlen('--execute='));
        }

        return $log;
    }

    private function shopPlan(string $as = 'shop_restored', bool $force = false): RestorePlan
    {
        return $this->plan(Selection::database(new DumpFile('mysql', 'shop', '/srv/dumps/mysql/shop.sql', 34), $as), null, force: $force);
    }

    private function config(): Config
    {
        mkdir("{$this->root}/dumps");

        return Configs::make(Configs::mysql(), Configs::pgsql(), "{$this->root}/dumps");
    }

    public function testImportIntoANewMysqlDatabase(): void
    {
        $this->mysqlServer();
        $config = $this->config();
        $plan = $this->shopPlan();

        self::assertSame(34, $this->runner()->preflight($this->restic, $plan, $config));
        $report = $this->runner()->run($this->restic, $plan, $config, 'h', $this->log(), self::sink());

        self::assertSame([
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'shop_restored'",
            'CREATE DATABASE `shop_restored`',
            'import shop_restored',
        ], $this->mysqlLog());
        self::assertSame(['dump', '--', $this->snapshot->id, '/srv/dumps/mysql/shop.sql'], $this->fake->callsOf('dump')[0]);
        self::assertStringStartsWith("{$this->root}/dumps/arkhive-import-", (string) self::last($this->runner->commands)?->inputFile, 'the dump is restored next to the dumps');
        self::assertStringNotContainsString('mysql-password-placeholder', $this->runner->everythingOnTheCommandLine());
        self::assertSame([], $this->cleanup->runAll());
        self::assertSame([], glob("{$this->root}/dumps/arkhive-import-*"), 'the restored dump file is removed');
        self::assertSame(['Check the data in shop_restored, then point the application at it or rename it; the original database shop was not touched'], $report->nextSteps);
        self::assertStringContainsString('success Imported mysql/shop (34 B) into the database shop_restored', $this->stream->contents());
    }

    public function testAnExistingDatabaseIsRefusedUnlessForced(): void
    {
        $this->mysqlServer(exists: true);
        $config = $this->config();

        try {
            $this->runner()->run($this->restic, $this->shopPlan('shop'), $config, 'h', $this->log(), self::sink());
            self::fail('expected a refusal');
        } catch (RefusedException $e) {
            self::assertSame('The database shop already exists: --import-as creates a new database and never imports over an existing one', $e->getMessage());
        }
        self::assertSame([], $this->fake->callsOf('dump'), 'nothing restored');

        $this->runner()->run($this->restic, $this->shopPlan('shop', force: true), $config, 'h', $this->log(), self::sink());
        self::assertSame([
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'shop'",
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'shop'",
            'import shop',
        ], $this->mysqlLog(), 'no CREATE, and never a DROP of an existing database');
        $this->cleanup->runAll();
        self::assertSame("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'shop'", $this->mysqlLog()[1]);
    }

    public function testAFailedImportDropsTheDatabaseItCreated(): void
    {
        $this->mysqlServer(importExit: 1);
        $config = $this->config();

        try {
            $this->runner()->run($this->restic, $this->shopPlan(), $config, 'h', $this->log(), self::sink());
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('Importing the dump into database shop_restored failed (mariadb exit 1): ERROR 1064 (42000) at line 1: syntax error', $e->getMessage());
        }
        self::assertSame([], $this->cleanup->runAll());
        self::assertSame('DROP DATABASE IF EXISTS `shop_restored`', self::last($this->mysqlLog()));
        self::assertSame([], glob("{$this->root}/dumps/arkhive-import-*"));
    }

    public function testATruncatedDumpIsNotImported(): void
    {
        $this->mysqlServer();
        $config = $this->config();

        try {
            $this->runner()->run($this->restic, $this->plan(Selection::database(new DumpFile('mysql', 'shop', '/srv/dumps/mysql/shop.sql', 999), 'shop_restored'), null), $config, 'h', $this->log(), self::sink());
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('The restored dump /srv/dumps/mysql/shop.sql has 34 bytes, the snapshot records 999', $e->getMessage());
        }
        $this->cleanup->runAll();
        self::assertNotContains('import shop_restored', $this->mysqlLog());
        self::assertSame('DROP DATABASE IF EXISTS `shop_restored`', self::last($this->mysqlLog()));
    }

    public function testImportIntoANewPostgresqlDatabase(): void
    {
        $sql = [];
        $this->runner->on('psql', function (Command $command) use (&$sql): array {
            self::assertSame('10', $command->env['PGCONNECT_TIMEOUT']);
            self::assertArrayHasKey('PGPASSFILE', $command->env);
            self::assertNull($command->env['PGPASSWORD']);
            foreach ($command->argv as $arg) {
                if (str_starts_with($arg, '--command=')) {
                    $sql[] = substr($arg, 10);

                    return [0, str_starts_with($arg, '--command=SELECT') ? "0\n" : '', ''];
                }
                if (str_starts_with($arg, '--file=')) {
                    $sql[] = 'import ' . implode(' ', array_filter($command->argv, static fn (string $a): bool => str_starts_with($a, '--dbname=') || $a === '--set=ON_ERROR_STOP=1'));
                    self::assertSame("dump of /srv/dumps/pgsql/crm.sql\n", file_get_contents(substr($arg, 7)));
                }
            }

            return [0, '', ''];
        });
        $config = $this->config();

        $this->runner()->run($this->restic, $this->plan(Selection::database(new DumpFile('pgsql', 'crm', '/srv/dumps/pgsql/crm.sql', 33), 'crm_copy'), null), $config, 'h', $this->log(), self::sink());

        self::assertSame([
            "SELECT COUNT(*) FROM pg_database WHERE datname = 'crm_copy'",
            'CREATE DATABASE "crm_copy"',
            'import --dbname=crm_copy --set=ON_ERROR_STOP=1',
        ], $sql);
        self::assertStringNotContainsString('pgsql-password-placeholder', $this->runner->everythingOnTheCommandLine());
    }

    public function testImportNeedsTheConnectionSettings(): void
    {
        foreach ([[null, '--import-as needs the database connection settings of a configuration file'], [Configs::make(), 'Importing a MySQL/MariaDB dump needs the MYSQL_* connection settings, and the configuration has none']] as [$config, $message]) {
            try {
                $this->runner()->run($this->restic, $this->shopPlan(), $config, 'h', $this->log(), self::sink());
                self::fail('expected a usage error');
            } catch (UsageException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
