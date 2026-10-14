<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use Tests\Support\CapturingTransport;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

final class MaintainCommandTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private const PACK = '9c44581e683bf99da4d31da1311e76e37c381784a311c637c56a2bd887b84af7';

    private FakeProcessRunner $runner;
    private FakeRestic $restic;
    private CapturingTransport $mail;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/locks");
        $this->terminal->env = ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'HOME' => "{$this->dir}/home"];
        $this->runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->install($this->runner);
        $this->restic->answers['forget'] = [0, FakeRestic::fixture('forget-0.19.json'), ''];
        $this->restic->answers['check'] = [0, FakeRestic::fixture('check-ok-0.19.jsonl'), ''];
        $this->restic->answers['stats'] = [0, FakeRestic::fixture('stats-raw-data-0.19.json'), ''];
        $this->mail = new CapturingTransport();
        $this->app->instance(ProcessRunner::class, $this->runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
        $this->app->instance(Notifier::class, new SmtpNotifier($this->mail->factory()));
    }

    private function damaged(): void
    {
        $this->restic->on['check'] = static fn (array $args): array => in_array('--read-data-subset=5%', $args, true)
            ? [1, FakeRestic::fixture('check-damaged-0.19.jsonl'), FakeRestic::fixture('check-damaged-0.19.stderr')]
            : [0, FakeRestic::fixture('check-ok-0.19.jsonl'), ''];
    }

    public function testAHealthyRunForgetsChecksAndReports(): void
    {
        $config = $this->writeConfig([...self::notify(), 'RESTIC_KEEP_DAILY' => '3', 'RESTIC_KEEP_WEEKLY' => '2', 'RESTIC_KEEP_MONTHLY' => '1']);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('maintain', ['--config' => $config, '--json' => true]));

        self::assertSame(['version'], $this->restic->calls[0]);
        self::assertSame(['cat', 'config'], $this->restic->calls[1]);
        self::assertSame(['forget', '--json', '--prune', '--host', 'web1.example.com', '--tag', 'arkhive', '--group-by', 'host', '--keep-daily', '3', '--keep-weekly', '2', '--keep-monthly', '1'], $this->restic->calls[2]);
        self::assertSame(['check', '--json'], $this->restic->calls[3]);
        self::assertSame(['check', '--json', '--read-data-subset=5%'], $this->restic->calls[4]);
        self::assertSame('stats', $this->restic->calls[5][0]);
        $log = $this->logOutput();
        self::assertStringContainsString('info Applying the retention policy to the snapshots of web1.example.com: 3 daily, 2 weekly, 1 monthly', $log);
        self::assertStringContainsString('success Kept 3 snapshots, removed 7 and pruned the data they no longer share', $log);
        self::assertStringContainsString('success Repository structure: no errors were found', $log);
        self::assertStringContainsString('success Data read back (5%): no errors were found', $log);
        self::assertStringContainsString('info Repository size: 51.45 KiB', $log);
        self::assertMatchesRegularExpression('/success Maintenance done in \d+\.\ds: the repository is healthy/', $log);

        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertTrue($json['healthy']);
        self::assertSame(3, $json['snapshots_kept']);
        self::assertCount(7, $json['snapshots_removed']);
        self::assertSame(['subset' => '5%', 'ok' => true, 'errors' => []], $json['read_data']);
        self::assertSame(52683, $json['raw_size']);

        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] maintenance OK on web1.example.com', $mail->getSubject());
        $body = (string) $mail->getTextBody();
        self::assertStringStartsWith("The maintenance completed and the repository is healthy.\n", $body);
        foreach (['Policy:          3 daily, 2 weekly, 1 monthly', 'Snapshots:       3 kept, 7 removed', 'Check:           no errors were found', 'Data read back:  5%: no errors were found', 'Repository size: 51.45 KiB'] as $line) {
            self::assertStringContainsString($line, $body);
        }
    }

    public function testTheCheckSubsetComesFromTheConfiguration(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('maintain', ['--config' => $this->writeConfig(['RESTIC_CHECK_SUBSET' => '1/10'])]));
        self::assertSame(['check', '--json', '--read-data-subset=1/10'], $this->restic->callsOf('check')[1]);
    }

    public function testADamagedPackIsExit1WithTheRepairStepsAndNotified(): void
    {
        $this->damaged();
        $config = $this->writeConfig(self::notify('failure'));

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('maintain', ['--config' => $config, '--json' => true]));

        $log = $this->logOutput();
        self::assertStringContainsString('error The repository check found errors: pack ' . self::PACK . ' contains 2 errors', $log);
        self::assertStringContainsString('notice Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then: restic repair packs ' . self::PACK . ' and restic repair snapshots --forget; back up again afterwards (ArkHive never repairs a repository automatically)', $log);
        self::assertSame([], $this->restic->callsOf('repair'), 'never repaired automatically');
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertFalse($json['healthy']);
        self::assertSame([self::PACK], $json['broken_packs']);

        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] maintenance found ERRORS on web1.example.com', $mail->getSubject());
        self::assertStringContainsString('Hint: Stop and read https://restic.readthedocs.io', (string) $mail->getTextBody());
    }

    public function testAFailedStructureCheckSkipsReadingTheData(): void
    {
        $this->restic->answers['check'] = [1, '', "Fatal: repository contains errors\nerror for tree 4b2a: tree 4b2a not found in repository\n"];

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('maintain', ['--config' => $this->writeConfig(), '--json' => true]));

        self::assertCount(1, $this->restic->callsOf('check'));
        self::assertStringContainsString('error The repository check found errors: error for tree 4b2a: tree 4b2a not found in repository', $this->logOutput());
        self::assertStringContainsString('notice Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; back up again afterwards', $this->logOutput());
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertNull($json['read_data']);
    }

    public function testAFailedForgetIsExit1AndNotified(): void
    {
        $this->restic->answers['forget'] = [11, '', FakeRestic::fixture('locked-0.19.stderr')];

        self::assertSame(ExitCode::TEMPORARY, Artisan::call('maintain', ['--config' => $this->writeConfig(self::notify())]));

        self::assertStringContainsString('error The restic repository is locked', $this->logOutput());
        self::assertEqualsWithDelta(300.0, $this->pause->total(), 1e-6, 'tried again until LOCK_WAIT');
        self::assertSame([], $this->restic->callsOf('check'));
        self::assertSame('[ArkHive] maintenance FAILED on web1.example.com', $this->mail->sent[0]->getSubject());
        self::assertStringStartsWith('The maintenance failed: The restic repository is locked', (string) $this->mail->sent[0]->getTextBody());
    }

    public function testItNeverRunsDuringABackup(): void
    {
        $config = $this->writeConfig();
        $backup = Lock::forRepository('/srv/restic-repo', ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks"], new FixedClock(), 0);
        $backup->acquire('backup');
        try {
            self::assertSame(ExitCode::TEMPORARY, Artisan::call('maintain', ['--config' => $config]));
        } finally {
            $backup->release();
        }

        self::assertStringContainsString('error Another ArkHive run holds the lock for this repository', $this->logOutput());
        self::assertStringContainsString('warning Waiting for the repository: Another ArkHive run holds the lock', $this->logOutput());
        self::assertSame([], $this->restic->callsOf('forget'));
    }

    public function testAStatsFailureIsOnlyAWarning(): void
    {
        $this->restic->answers['stats'] = [1, '', "Fatal: unable to load index\n"];

        self::assertSame(ExitCode::SUCCESS, Artisan::call('maintain', ['--config' => $this->writeConfig(self::notify())]));
        self::assertStringContainsString('warning Could not measure the repository: Measuring the repository failed (restic exit 1): Fatal: unable to load index', $this->logOutput());
        self::assertStringContainsString('Repository size: unknown', (string) $this->mail->sent[0]->getTextBody());
    }

    public function testANotificationFailureIsExit3(): void
    {
        $this->mail->failWith = 'Connection refused';

        self::assertSame(ExitCode::WARNING, Artisan::call('maintain', ['--config' => $this->writeConfig(self::notify())]));
    }

    public function testANotificationFailureDoesNotHideADamagedRepository(): void
    {
        $this->damaged();
        $this->mail->failWith = 'Connection refused';

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('maintain', ['--config' => $this->writeConfig(self::notify())]));
    }
}
