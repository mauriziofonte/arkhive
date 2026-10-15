<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Drill;

use DateTimeImmutable;
use Mfonte\Arkhive\Application\Drill\DrillRunner;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use Mfonte\Arkhive\Infrastructure\Restic\Restic;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\Configs;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;

/**
 * spec §10.5 on the sample repository: 5 files (82 B), two of them dumps (mysql/shop, pgsql/crm).
 */
final class DrillRunnerTest extends TestCase
{
    use TempDir;

    private const FILES = ['/srv/dumps/mysql/shop.sql', '/srv/dumps/pgsql/crm.sql', '/srv/www/site/css/app.css', '/srv/www/site/index.php', '/srv/www/with space/naïve file.txt'];

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
        // restic dump writes the recorded number of bytes
        $this->fake->on['dump'] = static function (array $args, Command $command): array {
            fwrite($command->output, str_repeat('-', 25));

            return [0, '', ''];
        };
        $this->restic = new Restic(new ResticSettings('/srv/restic-repo', '/etc/arkhive/restic-password'), '/usr/bin/restic', $this->runner);
        $this->cleanup = new Cleanup();
        $this->stream = LogStream::memory();
        $this->root = $this->tempDir();
        mkdir("{$this->root}/tmp");
        $this->snapshot = new Snapshot('6118b3c5b2b1ffc9e12d6022dfcab6275e9621122cb2b8d5b66d11a67fdc5c59', new DateTimeImmutable('2026-10-12T14:14:37Z'), 'web1.example.com', ['/srv/dumps', '/srv/www']);
    }

    private function drill(int $seed = 1): DrillRunner
    {
        return new DrillRunner(
            $this->runner,
            FakeBinaries::dependencies("{$this->root}/bin", ['restic', 'mariadb', 'psql']),
            $this->cleanup,
            new Randomizer(new Mt19937($seed)),
            "{$this->root}/tmp",
        );
    }

    private function log(): Logger
    {
        return new Logger(new ExecutionContext(false, false, false, LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock());
    }

    private function config(): Config
    {
        return Configs::make(Configs::mysql(), Configs::pgsql(), "{$this->root}/dumps");
    }

    /**
     * @return list<string> the --include values of the drill restore
     */
    private function includes(): array
    {
        $args = $this->fake->callsOf('restore')[0];
        $includes = [];
        foreach ($args as $i => $arg) {
            if ($arg === '--include') {
                $includes[] = $args[$i + 1];
            }
        }

        return $includes;
    }

    public function testASampleLargerThanTheSnapshotRestoresAndVerifiesEveryFile(): void
    {
        $report = $this->drill()->run($this->restic, $this->snapshot, 50, null, false, $this->log());

        self::assertTrue($report->passed());
        self::assertSame(self::FILES, $report->files);
        self::assertSame(5, $report->available);
        self::assertSame(82, $report->bytes);
        self::assertSame(['ls', '--json', '--recursive', '--', $this->snapshot->id], $this->fake->callsOf('ls')[0]);
        $restore = $this->fake->callsOf('restore')[0];
        self::assertSame(['restore', '--json', '--verify', '--sparse', '--target'], array_slice($restore, 0, 5));
        self::assertStringStartsWith("{$this->root}/tmp/arkhive-drill-", $restore[5]);
        self::assertSame(['--', $this->snapshot->id], array_slice($restore, -2));
        self::assertSame(self::FILES, $this->includes());
        self::assertStringContainsString('success Restored and verified 5 files (82 B)', $this->stream->contents());
        self::assertSame([], $this->cleanup->runAll());
        self::assertSame([], glob("{$this->root}/tmp/*"), 'everything is removed');
    }

    public function testASmallSampleIsRandomButReproducibleForASeed(): void
    {
        $first = $this->drill(7)->run($this->restic, $this->snapshot, 2, null, false, $this->log());
        $again = $this->drill(7)->run($this->restic, $this->snapshot, 2, null, false, $this->log());
        $seen = [];
        foreach (range(1, 20) as $seed) {
            $seen[implode('|', $this->drill($seed)->run($this->restic, $this->snapshot, 2, null, false, $this->log())->files)] = true;
        }

        self::assertCount(2, $first->files);
        self::assertSame($first->files, $again->files);
        self::assertSame(2, count(array_unique($first->files)));
        self::assertGreaterThan(3, count($seen), 'different seeds pick different files');
        self::assertTrue($first->passed());
        self::assertStringContainsString('info Restoring 2 random files of 5', $this->stream->contents());
    }

    public function testNamesWithGlobCharactersAreIncludedLiterally(): void
    {
        $this->fake->on['ls'] = static fn (): array => [0, '{"name":"a[1]*.txt","type":"file","path":"/srv/www/a[1]*.txt","size":3,"mode":420,"permissions":"-rw-r--r--","mtime":"2026-10-12T14:14:34Z","struct_type":"node"}' . "\n", ''];
        $this->fake->on['restore'] = static function (array $args): array {
            $target = $args[(int) array_search('--target', $args, true) + 1];
            mkdir("{$target}/srv/www", 0700, true);
            file_put_contents("{$target}/srv/www/a[1]*.txt", 'abc');

            return [0, '', ''];
        };

        self::assertTrue($this->drill()->run($this->restic, $this->snapshot, 50, null, false, $this->log())->passed());
        self::assertSame(['/srv/www/a\\[1\\]\\*.txt'], $this->includes());
    }

    public function testAMissingOrTruncatedFileFailsTheDrill(): void
    {
        $this->fake->on['restore'] = static function (array $args): array {
            $target = $args[(int) array_search('--target', $args, true) + 1];
            mkdir("{$target}/srv/www/site", 0700, true);
            file_put_contents("{$target}/srv/www/site/index.php", 'short');

            return [0, '', ''];
        };

        $report = $this->drill()->run($this->restic, $this->snapshot, 50, null, false, $this->log());

        self::assertFalse($report->passed());
        self::assertContains('/srv/dumps/mysql/shop.sql was not restored', $report->failures);
        self::assertContains('/srv/www/site/index.php has 5 bytes after the restore, the snapshot records 22', $report->failures);
        self::assertStringContainsString('error Drill: /srv/www/site/index.php has 5 bytes after the restore, the snapshot records 22', $this->stream->contents());
        self::assertSame([], glob("{$this->root}/tmp/*"));
    }

    public function testAFailedRestoreFailsTheDrill(): void
    {
        $this->fake->on['restore'] = static fn (): array => [1, '', "Fatal: failed to restore: pack 9c44581e: ciphertext verification failed\n"];

        $report = $this->drill()->run($this->restic, $this->snapshot, 50, null, false, $this->log());

        self::assertFalse($report->passed());
        self::assertSame(['the restore failed: restic restore (drill) failed (restic exit 1): Fatal: failed to restore: pack 9c44581e: ciphertext verification failed'], $report->failures);
        self::assertSame([], glob("{$this->root}/tmp/*"));
    }

    public function testAnEmptySnapshotFails(): void
    {
        $this->fake->on['ls'] = static fn (): array => [0, '', ''];

        $report = $this->drill()->run($this->restic, $this->snapshot, 50, null, false, $this->log());

        self::assertFalse($report->passed());
        self::assertSame(['snapshot 6118b3c5 contains no file to restore'], $report->failures);
        self::assertSame([], $this->fake->callsOf('restore'));
    }

    public function testNotEnoughTemporarySpaceIsARefusal(): void
    {
        $this->fake->on['ls'] = static fn (): array => [0, '{"name":"huge","type":"file","path":"/srv/huge","size":' . PHP_INT_MAX . ',"mode":420,"permissions":"-rw-r--r--","mtime":"2026-10-12T14:14:34Z","struct_type":"node"}' . "\n", ''];

        try {
            $this->drill()->run($this->restic, $this->snapshot, 50, null, false, $this->log());
            self::fail('expected a refusal');
        } catch (RefusedException $e) {
            self::assertStringStartsWith("Not enough free space in {$this->root}/tmp/arkhive-drill-", $e->getMessage());
            self::assertStringContainsString(' for the restore drill: ', $e->getMessage());
        }
        self::assertSame([], $this->fake->callsOf('restore'));
        self::assertSame([], glob("{$this->root}/tmp/*"));
    }

    public function testDbNeedsAConfigurationWithADatabase(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--db needs a configuration with WITH_MYSQL or WITH_PGSQL');
        $this->drill()->run($this->restic, $this->snapshot, 50, Configs::make(), true, $this->log());
    }

    public function testDbImportsOneDumpIntoAThrowAwayDatabaseAndDropsIt(): void
    {
        $sql = [];
        $this->runner->on('mariadb', static function (Command $command) use (&$sql): array {
            foreach ($command->argv as $arg) {
                if (str_starts_with($arg, '--execute=')) {
                    $sql[] = substr($arg, 10);

                    return [0, str_starts_with($arg, '--execute=SELECT') ? "0\n" : '', ''];
                }
            }
            $sql[] = 'import ' . $command->argv[array_key_last($command->argv)];

            return [0, '', ''];
        });

        $report = $this->drill()->run($this->restic, $this->snapshot, 1, Configs::make(Configs::mysql(), null, null), true, $this->log());

        self::assertTrue($report->passed());
        $database = $report->database;
        self::assertNotNull($database);
        self::assertSame('mysql', $database['engine'], 'only engines the configuration can import');
        self::assertSame('shop', $database['database']);
        self::assertMatchesRegularExpression('/^arkhive_drill_[0-9a-f]{8}$/', $database['imported_as']);
        $name = $database['imported_as'];
        self::assertSame([
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$name}'",
            "CREATE DATABASE `{$name}`",
            "import {$name}",
            "DROP DATABASE IF EXISTS `{$name}`",
        ], $sql);
        self::assertStringContainsString("success Imported mysql/shop into {$name}", $this->stream->contents());
        self::assertSame([], $this->cleanup->runAll());
        self::assertSame([], glob("{$this->root}/tmp/*"));
    }

    public function testAFailedImportFailsTheDrillAndStillDropsTheDatabase(): void
    {
        $dropped = false;
        $this->runner->on('psql', static function (Command $command) use (&$dropped): array {
            foreach ($command->argv as $arg) {
                if (str_starts_with($arg, '--command=DROP')) {
                    $dropped = true;
                }
                if (str_starts_with($arg, '--command=')) {
                    return [0, str_starts_with($arg, '--command=SELECT') ? "0\n" : '', ''];
                }
            }

            return [3, '', "psql:/tmp/crm.sql:1: ERROR:  syntax error at or near \"-\"\n"];
        });

        $report = $this->drill()->run($this->restic, $this->snapshot, 1, Configs::make(null, Configs::pgsql(), null), true, $this->log());

        self::assertFalse($report->passed());
        self::assertNotNull($report->database);
        self::assertSame('pgsql', $report->database['engine']);
        self::assertStringStartsWith('the test import of pgsql/crm failed: Importing the dump into database arkhive_drill_', (string) $report->database['error']);
        self::assertTrue($dropped);
        self::assertStringContainsString('error Drill: the test import of pgsql/crm failed', $this->stream->contents());
    }

    public function testATruncatedDumpFailsBeforeAnyDatabaseIsCreated(): void
    {
        $this->fake->on['dump'] = static function (array $args, Command $command): array {
            fwrite($command->output, 'short');

            return [0, '', ''];
        };

        $report = $this->drill()->run($this->restic, $this->snapshot, 1, Configs::make(Configs::mysql(), null, null), true, $this->log());

        self::assertFalse($report->passed());
        self::assertNotNull($report->database);
        self::assertSame('the test import of mysql/shop failed: the restored dump /srv/dumps/mysql/shop.sql has 5 bytes, the snapshot records 25', $report->database['error']);
        self::assertSame([], array_filter($this->runner->commands, static fn (Command $c): bool => $c->name() === 'mariadb'));
    }

    public function testASnapshotWithoutDumpsOfTheConfiguredEngineFailsTheDbTest(): void
    {
        $this->fake->on['ls'] = static function (array $args): array {
            $lines = array_filter(explode("\n", FakeRestic::fixture('ls-recursive-0.19.jsonl')), static fn (string $l): bool => !str_contains($l, '/srv/dumps/mysql'));
            if (!in_array('--recursive', $args, true)) {
                $lines = [];
            }

            return [0, implode("\n", $lines) . "\n", ''];
        };

        $report = $this->drill()->run($this->restic, $this->snapshot, 1, Configs::make(Configs::mysql(), null, null), true, $this->log());

        self::assertFalse($report->passed());
        self::assertSame('snapshot 6118b3c5 has no MySQL/MariaDB dump to test', $report->database['error'] ?? null);
    }

    public function testAnInterruptionPropagatesAfterRemovingTheDirectory(): void
    {
        $this->fake->on['restore'] = static function (): array {
            throw new InterruptedException(SIGTERM);
        };

        try {
            $this->drill()->run($this->restic, $this->snapshot, 50, null, false, $this->log());
            self::fail('expected the interruption');
        } catch (InterruptedException) {
        }
        self::assertSame([], glob("{$this->root}/tmp/*"), 'removed on the way out');
        self::assertSame([], $this->cleanup->runAll());
    }
}
