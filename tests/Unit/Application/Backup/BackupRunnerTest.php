<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Backup;

use Mfonte\Arkhive\Application\Backup\BackupRunner;
use Mfonte\Arkhive\Application\Configuration\ConfigFactory;
use Mfonte\Arkhive\Application\Database\DatabaseDumps;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use Mfonte\Arkhive\Infrastructure\Restic\ResticFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPause;
use Tests\Support\TempDir;

final class BackupRunnerTest extends TestCase
{
    use TempDir;

    private FakeProcessRunner $runner;
    private FakeRestic $restic;
    private Cleanup $cleanup;
    private RecordingPause $pause;
    private LogStream $stream;
    private string $root;
    private string $lockDir;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->install($this->runner);
        $this->cleanup = new Cleanup();
        $this->pause = new RecordingPause();
        $this->stream = LogStream::memory();
        $this->root = $this->tempDir();
        $this->lockDir = "{$this->root}/locks";
        mkdir($this->lockDir);
    }

    private function logger(bool $interactive = false): Logger
    {
        return new Logger(new ExecutionContext($interactive, false, false, $interactive ? LogFormat::Human : LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock());
    }

    private function backupRunner(): BackupRunner
    {
        $dependencies = FakeBinaries::dependencies("{$this->root}/bin", ['restic', 'mariadb-dump', 'mariadb']);

        return new BackupRunner(
            new ResticFactory($this->runner, $dependencies),
            new DatabaseDumps($this->runner, $dependencies, $this->cleanup),
            $this->cleanup,
            new FixedClock(),
            ['ARKHIVE_LOCK_DIR' => $this->lockDir],
            posix_geteuid(),
            new Retrier($this->pause),
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function config(array $values = []): Config
    {
        return ConfigFactory::create([
            'BACKUP_PATHS' => '/srv/www "/srv/my files"',
            'EXCLUSION_PATTERNS' => '*/node_modules/*,*.log,/cache/*',
            'RESTIC_REPOSITORY' => 'sftp:backup-user@backup.example.com:/restic',
            'RESTIC_PASSWORD_FILE' => '/etc/arkhive/restic-password',
            'RESTIC_HOST' => 'web1.example.com',
            'RESTIC_COMPRESSION' => 'max',
            ...$values,
        ], '/etc/arkhive/config', 'host.example.com', false);
    }

    private function lock(): Lock
    {
        return Lock::forRepository('sftp:backup-user@backup.example.com:/restic', ['ARKHIVE_LOCK_DIR' => $this->lockDir], new FixedClock(), 0);
    }

    public function testBackupWithTheSpecArgumentsAndTheConvertedExcludes(): void
    {
        $lockedDuringBackup = null;
        $this->restic->duringBackup = function () use (&$lockedDuringBackup): void {
            $lockedDuringBackup = $this->lock()->holder();
        };

        $report = $this->backupRunner()->run($this->config(), $this->logger());

        self::assertSame('e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', $report->summary->snapshotId);
        self::assertSame('0.19.1', $report->resticVersion);
        self::assertSame([], $report->warnings);
        self::assertFalse($report->hasWarnings());
        self::assertSame([], $report->dumps);

        $args = $this->restic->backups[0];
        $excludeFile = $args[array_search('--exclude-file', $args, true) + 1];
        self::assertSame(
            ['backup', '--json', '--tag', 'arkhive', '--host', 'web1.example.com', '--compression', 'max', '--exclude-file', $excludeFile, '--', '/srv/www', '/srv/my files'],
            $args,
        );
        self::assertSame(
            "/srv/www/**/node_modules\n/srv/my files/**/node_modules\n/srv/www/**/*.log\n/srv/my files/**/*.log\n/srv/www/cache/*\n/srv/my files/cache/*\n",
            $this->restic->excludeFiles[0],
        );
        self::assertFileDoesNotExist($excludeFile);
        self::assertSame('backup', $lockedDuringBackup['operation'] ?? null);
        self::assertSame(['version', 'cat', 'backup'], array_map(static fn (Command $c): string => $c->argv[1] === '--option' ? $c->argv[3] : $c->argv[1], $this->runner->commands));
        self::assertArrayNotHasKey('RESTIC_PROGRESS_FPS', $this->runner->commands[2]->env);
        self::assertSame(1, $this->cleanup->pending(), 'only the lock release is left for the end of the command');
        $this->cleanup->runAll();
        self::assertSame([], $this->lock()->holder(), 'released by the cleanup registry');
    }

    public function testProgressIsRequestedAndShownOnlyInInteractiveMode(): void
    {
        $this->restic->backup = [0, FakeRestic::fixture('backup-0.19-progress.jsonl'), ''];

        $this->backupRunner()->run($this->config(), $this->logger(true));

        self::assertSame('2', $this->runner->commands[2]->env['RESTIC_PROGRESS_FPS']);
        self::assertStringContainsString(' 40.0%  9/30 files  34.33 MiB/85.83 MiB', $this->stream->contents());
        $this->cleanup->runAll();
    }

    public function testExit3IsAWarningListingWhatWasNotBackedUp(): void
    {
        $errors = '{"message_type":"error","error":{"message":"open /srv/www/secret: permission denied"},"during":"archival","item":"/srv/www/secret"}' . "\n";
        $this->restic->backup = [3, $errors . FakeRestic::fixture('backup-0.19-incomplete.jsonl'), FakeRestic::fixture('backup-0.19-incomplete.stderr')];

        $report = $this->backupRunner()->run($this->config(), $this->logger());

        self::assertSame(['the snapshot was saved but 2 source item(s) could not be read'], $report->warnings);
        self::assertTrue($report->hasWarnings());
        $warnings = array_values(array_filter(explode("\n", $this->stream->contents()), static fn (string $l): bool => str_contains($l, ' warning ')));
        self::assertSame([
            '2026-10-12T10:20:30+02:00 warning Not backed up: /srv/www/secret: open /srv/www/secret: permission denied',
            '2026-10-12T10:20:30+02:00 warning Not backed up: /srv/www/missing does not exist, skipping',
        ], $warnings);
        $this->cleanup->runAll();
    }

    public function testManyUnreadableFilesAreSummarised(): void
    {
        $lines = '';
        for ($i = 0; $i < 25; $i++) {
            $lines .= json_encode(['message_type' => 'error', 'error' => ['message' => 'permission denied'], 'item' => "/srv/www/f{$i}"]) . "\n";
        }
        $this->restic->backup = [3, $lines . FakeRestic::fixture('backup-0.19-incomplete.jsonl'), ''];

        $report = $this->backupRunner()->run($this->config(), $this->logger());

        self::assertSame(['the snapshot was saved but 25 source item(s) could not be read'], $report->warnings);
        self::assertSame(21, substr_count($this->stream->contents(), 'warning'));
        self::assertStringContainsString('warning ... and 5 more files that could not be read', $this->stream->contents());
        $this->cleanup->runAll();
    }

    public function testAFailedBackupReleasesTheLockAndRemovesTheExcludeFile(): void
    {
        $this->restic->backup = [1, '', "Fatal: unable to save snapshot: disk full\n"];

        try {
            $this->backupRunner()->run($this->config(), $this->logger());
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('restic backup failed (restic exit 1): Fatal: unable to save snapshot: disk full', $e->getMessage());
        }
        $this->cleanup->runAll();
        self::assertSame([], $this->lock()->holder());
        self::assertSame([], glob(sys_get_temp_dir() . '/arkhive-exclude-*') ?: [], 'no exclude file left behind');
    }

    public function testNoSummaryIsAFailure(): void
    {
        $this->restic->backup = [0, "not json\n", "something odd\n"];

        $this->expectExceptionObject(new ArkhiveException('restic backup ended without reporting a snapshot: something odd'));
        try {
            $this->backupRunner()->run($this->config(), $this->logger());
        } finally {
            $this->cleanup->runAll();
        }
    }

    public function testAnotherRunHoldingTheLockStopsBeforeTouchingTheRepository(): void
    {
        $holder = $this->lock();
        $holder->acquire('maintain');
        try {
            $this->backupRunner()->run($this->config(), $this->logger());
            self::fail('expected the lock to be refused');
        } catch (ArkhiveException $e) {
            self::assertStringStartsWith('Another ArkHive run holds the lock for this repository (PID ' . getmypid() . ', maintain, started 2026-10-12T10:20:30+02:00)', $e->getMessage());
        } finally {
            $holder->release();
        }
        self::assertSame(['version'], array_map(static fn (Command $c): string => $c->argv[1], $this->runner->commands));
    }

    public function testAStaleLockIsReportedAndTheBackupRuns(): void
    {
        file_put_contents($this->lock()->path(), "pid=999999\nstarted=2026-10-11T03:00:00+02:00\noperation=backup\n");

        $this->backupRunner()->run($this->config(), $this->logger());

        self::assertStringContainsString('warning A previous ArkHive run (PID 999999, backup, started 2026-10-11T03:00:00+02:00) ended without releasing its lock', $this->stream->contents());
        $this->cleanup->runAll();
    }

    public function testAnUnreachableRepositoryStopsBeforeTheDumps(): void
    {
        $this->restic->catConfig = [10, '', FakeRestic::fixture('no-repository-0.19.stderr')];

        try {
            $this->backupRunner()->run($this->config(['WITH_MYSQL' => 'true', 'MYSQL_USER' => 'backup', 'MYSQL_DATABASES' => 'shop', 'DUMP_DIRECTORY' => "{$this->root}/dumps"]), $this->logger());
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('There is no restic repository at sftp:backup-user@backup.example.com:/restic', $e->getMessage());
        }
        foreach ($this->runner->commands as $command) {
            self::assertNotSame('mariadb-dump', $command->name());
        }
        $this->cleanup->runAll();
    }

    public function testDumpsGoIntoTheSnapshotAndAreRemovedAfterwards(): void
    {
        $dumpDir = "{$this->root}/dumps";
        $this->runner
            ->on('mariadb', static fn (): array => [0, "100\n", ''])
            ->on('mariadb-dump', static function (Command $c): int {
                file_put_contents(substr($c->argv[8], strlen('--result-file=')), 'CREATE TABLE t (id INT);');

                return 0;
            });
        $inSnapshot = null;
        $this->restic->duringBackup = static function () use (&$inSnapshot, $dumpDir): void {
            $inSnapshot = glob("{$dumpDir}/mysql/*") ?: [];
        };

        $report = $this->backupRunner()->run($this->config(['WITH_MYSQL' => 'true', 'MYSQL_USER' => 'backup', 'MYSQL_DATABASES' => 'shop', 'DUMP_DIRECTORY' => $dumpDir]), $this->logger());

        self::assertSame(["{$dumpDir}/mysql/shop.sql"], $inSnapshot);
        self::assertSame($dumpDir, end($this->restic->backups[0]));
        self::assertCount(1, $report->dumps);
        self::assertSame([], glob("{$dumpDir}/mysql/*") ?: [], 'emptied after the backup');
        $this->cleanup->runAll();
    }

    public function testDumpsAreRemovedWhenTheBackupFails(): void
    {
        $dumpDir = "{$this->root}/dumps";
        $this->runner
            ->on('mariadb', static fn (): array => [0, "100\n", ''])
            ->on('mariadb-dump', static function (Command $c): int {
                file_put_contents(substr($c->argv[8], strlen('--result-file=')), 'x');

                return 0;
            });
        $this->restic->backup = [1, '', "Fatal: connection lost\n"];

        try {
            $this->backupRunner()->run($this->config(['WITH_MYSQL' => 'true', 'MYSQL_USER' => 'backup', 'MYSQL_DATABASES' => 'shop', 'DUMP_DIRECTORY' => $dumpDir]), $this->logger());
            self::fail('expected a failure');
        } catch (ArkhiveException) {
            self::assertSame([], glob("{$dumpDir}/mysql/*") ?: []);
        }
        $this->cleanup->runAll();
    }
}
