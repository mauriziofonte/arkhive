<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Resilience\StaleLocks;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use Tests\Support\CapturingTransport;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeProcessTable;
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
        $this->restic->answers['prune'] = [0, "loading all snapshots...\nremoving 2 old packs\ndone\n", ''];
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

    public function testAHealthyRunChecksThenForgetsAndPrunesAndReports(): void
    {
        $config = $this->writeConfig([...self::notify(), 'RESTIC_KEEP_DAILY' => '3', 'RESTIC_KEEP_WEEKLY' => '2', 'RESTIC_KEEP_MONTHLY' => '1']);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('maintain', ['--config' => $config, '--json' => true]));

        self::assertSame(['version'], $this->restic->calls[0]);
        self::assertSame(['cat', 'config'], $this->restic->calls[1]);
        self::assertSame(['check', '--json'], $this->restic->calls[2]);
        self::assertSame(['check', '--json', '--read-data-subset=5%'], $this->restic->calls[3]);
        self::assertSame(['forget', '--json', '--host', 'web1.example.com', '--tag', 'arkhive', '--group-by', 'host', '--keep-daily', '3', '--keep-weekly', '2', '--keep-monthly', '1'], $this->restic->calls[4]);
        self::assertSame(['prune'], $this->restic->calls[5]);
        self::assertSame('stats', $this->restic->calls[6][0]);
        $log = $this->logOutput();
        self::assertStringContainsString('info Applying the retention policy to the snapshots of web1.example.com: 3 daily, 2 weekly, 1 monthly', $log);
        self::assertStringContainsString('success Kept 3 snapshots, removed 7', $log);
        self::assertStringContainsString('success Pruned the data no snapshot uses any more', $log);
        self::assertStringContainsString('success Repository structure: no errors were found', $log);
        self::assertStringContainsString('success Data read back (5%): no errors were found', $log);
        self::assertStringContainsString('info Repository size: 51.45 KiB', $log);
        self::assertMatchesRegularExpression('/success Maintenance done in \d+\.\ds: the repository is healthy/', $log);

        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertTrue($json['healthy']);
        self::assertTrue($json['retention_applied']);
        self::assertNull($json['retention_error']);
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

    public function testTheOutcomeIsRecorded(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('maintain', ['--config' => $this->writeConfig()]));
        self::assertSame(ExitCode::SUCCESS, $this->runs->load('maintain')->last?->exitCode);

        $this->restic->answers['forget'] = [1, '', "Fatal: unable to load index\n"];
        self::assertSame(ExitCode::FAILURE, Artisan::call('maintain', ['--config' => $this->writeConfig()]));
        self::assertSame(ExitCode::FAILURE, $this->runs->load('maintain')->last?->exitCode);
        self::assertSame(ExitCode::SUCCESS, $this->runs->load('maintain')->lastSuccess?->exitCode);
        self::assertSame([], array_diff(array_keys($this->runs->states), ['maintain']));
    }

    public function testTheCheckSubsetComesFromTheConfiguration(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('maintain', ['--config' => $this->writeConfig(['RESTIC_CHECK_SUBSET' => '1/10'])]));
        self::assertSame(['check', '--json', '--read-data-subset=1/10'], $this->restic->callsOf('check')[1]);
    }

    public function testADamagedPackIsExit65WithTheRepairStepsAndNotified(): void
    {
        $this->damaged();
        $config = $this->writeConfig(self::notify('failure'));

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('maintain', ['--config' => $config, '--json' => true]));

        $log = $this->logOutput();
        self::assertStringContainsString('error The repository check found errors: pack ' . self::PACK . ' contains 2 errors', $log);
        self::assertStringContainsString('notice Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then run, in this order: restic repair packs ' . self::PACK . ', then arkhive backup (uploads again what is still on this host), then restic repair snapshots --forget, then arkhive maintain (restic as: RESTIC_PASSWORD_FILE=', $log);
        self::assertStringContainsString("restic -r '/srv/restic-repo'). ArkHive never repairs a repository automatically", $log);
        self::assertSame([], $this->restic->callsOf('repair'), 'never repaired automatically');
        self::assertSame([], $this->restic->callsOf('forget'), 'a damaged repository keeps every snapshot');
        self::assertSame([], $this->restic->callsOf('prune'));
        self::assertStringContainsString('warning The retention policy was not applied: the repository check found errors, so no snapshot was removed', $log);
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertFalse($json['healthy']);
        self::assertFalse($json['retention_applied']);
        self::assertSame([], $json['snapshots_removed']);
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
        self::assertStringContainsString('notice Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then run, in this order: restic repair index, then arkhive backup', $this->logOutput());
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
        self::assertSame([], $this->restic->callsOf('prune'));
        self::assertSame('[ArkHive] maintenance FAILED on web1.example.com', $this->mail->sent[0]->getSubject());
        self::assertStringStartsWith('The maintenance failed: The restic repository is locked', (string) $this->mail->sent[0]->getTextBody());
    }

    /**
     * forget refused on the lock of a restic killed on this host (PID 25983 of host.example.com, now
     * gone) until `restic unlock` runs; the ArkHive lock is the real one, held by this maintain.
     */
    private function lockedByADeadProcess(): void
    {
        $this->app->instance(StaleLocks::class, new StaleLocks(
            new FakeProcessTable(),
            new FixedClock(),
            'host.example.com',
            fn (string $repository): bool => Lock::forRepository($repository, $this->terminal->env, new FixedClock(), posix_geteuid())->heldElsewhere(),
        ));
        $unlocked = false;
        $this->restic->on['forget'] = static function () use (&$unlocked): array {
            return $unlocked ? [0, FakeRestic::fixture('forget-0.19.json'), ''] : [11, '', FakeRestic::fixture('locked-0.19.stderr')];
        };
        $this->restic->on['unlock'] = static function () use (&$unlocked): array {
            $unlocked = true;

            return [0, "successfully removed 1 locks\n", ''];
        };
        $this->restic->on['cat'] = static fn (array $args): array => $args[1] === 'lock' ? [0, FakeRestic::fixture('lock-0.19.json'), ''] : [0, "{\"version\": 2}\n", ''];
        $this->restic->answers['list'] = [0, str_repeat('6d287ce8', 8) . "\n", ''];
    }

    public function testALockLeftByADeadProcessOnThisHostFailsAtOnceAndIsNeverRemovedByDefault(): void
    {
        $this->lockedByADeadProcess();

        self::assertSame(ExitCode::TEMPORARY, Artisan::call('maintain', ['--config' => $this->writeConfig()]));

        $log = $this->logOutput();
        self::assertStringContainsString('error The restic repository is locked by PID 25983 on host.example.com by root (UID 0, GID 0), created at 2026-10-12 13:58:02 (1.554904747s ago): a stale lock from a dead process on this host (PID 25983 no longer exists)', $log);
        self::assertStringContainsString("notice Nothing will release it. Remove it with: RESTIC_PASSWORD_FILE='{$this->dir}/password' restic -r '/srv/restic-repo' unlock (restic removes only the locks it finds stale), or set UNLOCK_STALE=yes", $log);
        self::assertSame(0.0, $this->pause->total(), 'LOCK_WAIT is not spent on a lock nothing will release');
        self::assertSame([], $this->restic->callsOf('unlock'));
        self::assertSame([], $this->restic->callsOf('list'));
    }

    public function testUnlockStaleRemovesTheLockOfADeadProcessAndCarriesOn(): void
    {
        $this->lockedByADeadProcess();

        self::assertSame(ExitCode::SUCCESS, Artisan::call('maintain', ['--config' => $this->writeConfig(['UNLOCK_STALE' => 'yes'])]));

        self::assertSame([['unlock']], $this->restic->callsOf('unlock'), 'restic unlock, never --remove-all');
        self::assertCount(2, $this->restic->callsOf('forget'));
        self::assertSame(0.0, $this->pause->total());
        $log = $this->logOutput();
        self::assertStringContainsString('warning restic forget: The restic repository is locked by PID 25983 on host.example.com by root (UID 0, GID 0), created at 2026-10-12 13:58:02 (1.554904747s ago): a stale lock from a dead process on this host (PID 25983 no longer exists); removing it with restic unlock (UNLOCK_STALE)', $log);
        self::assertStringContainsString('info Stale lock removed; trying again', $log);
        self::assertStringContainsString('success Repository structure: no errors were found', $log);
    }

    public function testPackFilesDeletedOnTheServerAreExit65WithTheRepairOrderAndNoSnapshotIsForgotten(): void
    {
        // the check runs first: on missing data, forget never runs, so the snapshots stay as they were
        $this->restic->answers['check'] = [1, FakeRestic::fixture('check-missing-0.19.jsonl'), FakeRestic::fixture('check-missing-0.19.stderr')];
        $config = $this->writeConfig(self::notify('failure'));

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('maintain', ['--config' => $config, '--json' => true]));

        $log = $this->logOutput();
        self::assertStringContainsString('warning The retention policy was not applied: the repository check found errors, so no snapshot was removed', $log);
        self::assertStringContainsString('error The repository check found errors: 3 pack file(s) listed in the index are missing from the repository (a1d3d946, 381d4654, ca8af500): the snapshots that use them cannot be restored in full', $log);
        self::assertStringContainsString('notice Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then run, in this order: restic repair index, then arkhive backup (uploads again what is still on this host), then restic repair snapshots --forget, then arkhive maintain', $log);
        self::assertStringNotContainsString('message_type', $log, 'no raw restic JSON');
        self::assertCount(1, $this->restic->callsOf('check'), 'no data read back on a broken structure');
        self::assertSame([], $this->restic->callsOf('forget'));
        self::assertSame([], $this->restic->callsOf('prune'));
        self::assertSame([], $this->restic->callsOf('repair'), 'never repaired automatically');
        self::assertSame([], $this->restic->callsOf('unlock'));

        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertFalse($json['healthy']);
        self::assertFalse($json['retention_applied']);
        self::assertSame(0, $json['snapshots_kept']);
        self::assertSame([], $json['snapshots_removed']);
        self::assertCount(3, $json['missing_packs']);
        self::assertSame([], $json['broken_packs']);
        self::assertSame('the repository check found errors, so no snapshot was removed', $json['retention_error']);

        $body = (string) $this->mail->sent[0]->getTextBody();
        self::assertSame('[ArkHive] maintenance found ERRORS on web1.example.com', $this->mail->sent[0]->getSubject());
        self::assertStringContainsString('Hint: Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then run, in this order: restic repair index', $body);
        self::assertStringContainsString('Snapshots:       none removed', $body);
        self::assertStringContainsString('Retention:       not applied: the repository check found errors, so no snapshot was removed', $body);
        self::assertSame(ExitCode::DATA_ERROR, $this->runs->load('maintain')->last?->exitCode);
    }

    public function testARefusedPruneStillReportsTheSnapshotsForgetRemovedAndFailsTheRun(): void
    {
        // the checks passed, forget removed 7 snapshots, then prune found missing data (captured from restic 0.19.1)
        $this->restic->answers['prune'] = [1, '', FakeRestic::fixture('prune-missing-0.19.stderr')];
        $config = $this->writeConfig(self::notify('failure'));

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('maintain', ['--config' => $config, '--json' => true]));

        $log = $this->logOutput();
        self::assertStringContainsString('success Kept 3 snapshots, removed 7', $log);
        self::assertStringContainsString('error The unused data was not pruned: Pruning the data no snapshot uses (restic prune) failed: 1 pack file(s) listed in the index are missing from the repository /srv/restic-repo (330afd54)', $log);
        self::assertStringContainsString('restic repair index', $log);
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertFalse($json['healthy']);
        self::assertTrue($json['retention_applied']);
        self::assertCount(7, $json['snapshots_removed'], 'what restic actually removed');
        self::assertStringContainsString('1 pack file(s) listed in the index are missing', (string) $json['retention_error']);
        self::assertStringContainsString('Snapshots:       3 kept, 7 removed', (string) $this->mail->sent[0]->getTextBody());
    }

    public function testARefusedForgetIsReportedAsNotApplied(): void
    {
        $this->restic->answers['forget'] = [1, '', FakeRestic::fixture('forget-missing-0.17.stderr')];

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('maintain', ['--config' => $this->writeConfig(), '--json' => true]));
        self::assertStringContainsString('error The retention policy was not applied: Applying the retention policy (restic forget) failed: 3 pack file(s) listed in the index are missing', $this->logOutput());
        self::assertSame([], $this->restic->callsOf('prune'));
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertFalse($json['retention_applied']);
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
