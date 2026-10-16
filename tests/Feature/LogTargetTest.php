<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Execution\MessageId;
use Mfonte\Arkhive\ExitCode;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

/**
 * --log-target and LOG_TARGET: events go to the journal or syslog with structured fields, a run
 * opens and closes with a MESSAGE_ID event, and stderr is the fallback.
 */
final class LogTargetTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private FakeRestic $restic;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/locks");
        $this->terminal->env = ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'HOME' => "{$this->dir}/home"];
        $runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->install($runner);
        $this->app->instance(ProcessRunner::class, $runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
    }

    public function testUnderSystemdTheJournalIsChosenWhenStderrIsConnectedToIt(): void
    {
        $this->terminal->env += ['INVOCATION_ID' => '6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', 'JOURNAL_STREAM' => '8:41077'];
        $this->terminal->stderr = '8:41077';

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));

        self::assertSame('', $this->logOutput(), 'nothing twice: the journal has every event');
        self::assertSame(['journal'], $this->events->opened);
        $first = $this->events->events[0];
        self::assertSame('arkhive backup started', $first['message']);
        self::assertSame(MessageId::BackupStarted->value, $first['fields']['MESSAGE_ID']);
        self::assertSame('backup', $first['fields']['ARKHIVE_COMMAND']);
        self::assertSame(config('app.version'), $first['fields']['ARKHIVE_VERSION']);
        $runId = $first['fields']['ARKHIVE_RUN_ID'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $runId);
        foreach ($this->events->events as $event) {
            self::assertSame($runId, $event['fields']['ARKHIVE_RUN_ID'], 'every event of the run carries its id');
        }
        self::assertContains('Snapshot e8ecb7f4 saved: 1.24 MiB added (1.22 MiB processed), 51 new, 0 changed, 0 unmodified files in 0.7s', $this->events->messages());

        $closing = $this->events->withMessageId(MessageId::BackupSucceeded->value);
        self::assertNotNull($closing);
        self::assertSame('arkhive backup succeeded', $closing['message']);
        self::assertSame('success', $closing['level']);
        self::assertSame('0', $closing['fields']['ARKHIVE_EXIT_STATUS']);
        self::assertSame('e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', $closing['fields']['ARKHIVE_SNAPSHOT']);
        self::assertSame($closing, $this->events->events[count($this->events->events) - 1], 'the run closes with it');

        // a second run in the same process gets a new id
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));
        self::assertNotSame($runId, $this->events->events[count($this->events->events) - 1]['fields']['ARKHIVE_RUN_ID']);
    }

    public function testStderrRedirectedAwayFromTheJournalStaysStderr(): void
    {
        $this->terminal->env += ['INVOCATION_ID' => '6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', 'JOURNAL_STREAM' => '8:41077'];
        $this->terminal->stderr = '8:99';

        self::assertSame(ExitCode::SUCCESS, Artisan::call('internal:context'));

        $context = json_decode(Artisan::output(), true);
        self::assertIsArray($context);
        self::assertSame(['stderr', 'default'], [$context['log_target'], $context['log_target_reason']]);
        self::assertSame([], $this->events->opened);
    }

    public function testAFailedRunIsClosedWithItsExitStatus(): void
    {
        $this->restic->catConfig = [10, '', FakeRestic::fixture('no-repository-0.19.stderr')];

        self::assertSame(ExitCode::UNAVAILABLE, Artisan::call('backup', ['--config' => $this->writeConfig(), '--log-target' => 'journal']));

        $closing = $this->events->withMessageId(MessageId::BackupFailed->value);
        self::assertNotNull($closing);
        self::assertSame('error', $closing['level']);
        self::assertSame('arkhive backup failed with exit 69 (UNAVAILABLE): There is no restic repository at /srv/restic-repo', $closing['message']);
        self::assertSame('69', $closing['fields']['ARKHIVE_EXIT_STATUS']);
        self::assertArrayNotHasKey('ARKHIVE_SNAPSHOT', $closing['fields']);
        self::assertContains('There is no restic repository at /srv/restic-repo', $this->events->messages());
        self::assertSame('', $this->logOutput());
    }

    public function testTheConfigurationChoosesSyslog(): void
    {
        $config = $this->writeConfig(['LOG_TARGET' => 'syslog', 'SYSLOG_FACILITY' => 'local3']);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $config]));

        self::assertSame(['syslog/local3'], $this->events->opened);
        self::assertSame('arkhive backup started', $this->events->events[0]['message'], 'the opening event follows to syslog');
        self::assertNotNull($this->events->withMessageId(MessageId::BackupSucceeded->value));
        self::assertSame('', $this->logOutput(), 'stderr only before the configuration was read: nothing logged then');

        // --log-target wins over LOG_TARGET
        $this->events->events = $this->events->opened = [];
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $config, '--log-target' => 'stderr']));
        self::assertSame([], $this->events->opened);
        self::assertStringContainsString('success Snapshot e8ecb7f4 saved', $this->logOutput());
    }

    public function testCommandsThatAreNotRunsHaveNoMessageIds(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('internal:context', ['--log-target' => 'syslog', '--sample-log' => true]));

        self::assertSame(['sample info', 'sample success', 'sample notice', 'sample warning', 'sample error'], $this->events->messages());
        foreach ($this->events->events as $event) {
            self::assertArrayNotHasKey('MESSAGE_ID', $event['fields']);
            self::assertSame('internal:context', $event['fields']['ARKHIVE_COMMAND']);
        }
        $context = json_decode(Artisan::output(), true);
        self::assertIsArray($context);
        self::assertSame(['syslog', '--log-target'], [$context['log_target'], $context['log_target_reason']]);
    }

    public function testAnUnreachableTargetFallsBackToStderr(): void
    {
        $this->events->reachable = false;

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig(), '--log-target' => 'journal']));

        self::assertStringContainsString('success Snapshot e8ecb7f4 saved', $this->logOutput());
        self::assertSame([], $this->events->events);
    }

    public function testAnInvalidTargetIsAUsageError(): void
    {
        self::assertSame(ExitCode::USAGE, Artisan::call('status', ['--log-target' => 'file']));
        self::assertStringContainsString('error Invalid --log-target "file": use auto, stderr, journal or syslog', $this->logOutput());
        self::assertSame([], $this->events->opened);

        self::assertSame(ExitCode::CONFIG, Artisan::call('backup', ['--config' => $this->writeConfig(['LOG_TARGET' => 'file'])]));
        self::assertStringContainsString('LOG_TARGET must be auto, stderr, journal or syslog, got "file"', $this->logOutput());
    }
}
