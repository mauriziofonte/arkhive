<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\ExitCode;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

/**
 * spec §6 status, on the sample repository: web1.example.com has three backups (newest e82340a7 at
 * 14:17:53Z), web2.example.com only a safety snapshot.
 */
final class StatusCommandTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private FakeRestic $restic;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        $runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->withRepository()->install($runner);
        $this->restic->on['stats'] = static fn (): array => [0, FakeRestic::fixture('stats-raw-data-0.19.json'), ''];
        $this->app->instance(ProcessRunner::class, $runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
        $this->clock->now = new DateTimeImmutable('2026-10-12 20:17:53', new DateTimeZone('UTC'));
    }

    public function testTheConfiguredHostAsJson(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $this->writeConfig(), '--json' => true]));

        $status = json_decode(Artisan::output(), true);
        self::assertSame([
            'repository' => '/srv/restic-repo',
            'host' => 'web1.example.com',
            'latest' => [
                'id' => 'e82340a77eda41788f329309f282cd62f1d4cad85933d8b22b6e4e72a0ab05b2',
                'short_id' => 'e82340a7',
                'time' => '2026-10-12T14:17:53+00:00',
                'age_hours' => 6.0,
            ],
            'snapshots' => 3,
            'snapshots_total' => 4,
            'hosts' => ['web1.example.com', 'web2.example.com'],
            'raw_size' => 52683,
            'uncompressed_size' => 54295,
            'runs' => [
                'backup' => ['last' => null, 'last_success' => null],
                'maintain' => ['last' => null, 'last_success' => null],
                'drill' => ['last' => null, 'last_success' => null],
            ],
        ], $status);
        self::assertSame(['stats', '--json', '--no-lock', '--mode', 'raw-data'], $this->restic->callsOf('stats')[0]);
    }

    public function testTheHumanSummary(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $this->writeConfig()]));

        self::assertSame(
            "Repository:      /srv/restic-repo\n"
            . "Host:            web1.example.com\n"
            . "Latest snapshot: e82340a7 at 2026-10-12 14:17 UTC (6 h ago)\n"
            . "Snapshots:       3 (4 in the repository)\n"
            . "Repository size: 51.45 KiB\n",
            Artisan::output(),
        );
    }

    public function testTheLastRunsOfThisHost(): void
    {
        $at = new DateTimeImmutable('2026-10-12 14:17:00', new DateTimeZone('UTC'));
        $good = new RunRecord('backup', $at->modify('-1 day'), $at->modify('-1 day +62 seconds'), 0, 'aaaa1111bbbb2222');
        $this->runs->states['backup'] = (new JobState())->with($good)->with(new RunRecord('backup', $at, $at->modify('+3 seconds'), 75, error: 'The restic repository is locked'));
        $this->runs->states['maintain'] = (new JobState())->with(new RunRecord('maintain', $at, $at->modify('+40 seconds'), 0));

        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $this->writeConfig()]));
        $output = Artisan::output();
        self::assertStringContainsString("Last backup:      2026-10-12 14:17 UTC, FAILED with exit 75: The restic repository is locked\n", $output);
        self::assertStringContainsString("Last good backup: 2026-10-11 14:17 UTC, OK, snapshot aaaa1111\n", $output);
        self::assertStringContainsString("Last maintain:    2026-10-12 14:17 UTC, OK\n", $output);
        self::assertStringNotContainsString('Last drill', $output);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $this->writeConfig(), '--json' => true]));
        $runs = json_decode(Artisan::output(), true)['runs'];
        self::assertSame(['job' => 'backup', 'started' => '2026-10-12T14:17:00+00:00', 'ended' => '2026-10-12T14:17:03+00:00', 'duration_seconds' => 3.0, 'exit_code' => 75, 'status' => 'failed', 'snapshot_id' => null, 'warnings' => [], 'error' => 'The restic repository is locked'], $runs['backup']['last']);
        self::assertSame('aaaa1111bbbb2222', $runs['backup']['last_success']['snapshot_id']);
        self::assertSame(62.0, $runs['backup']['last_success']['duration_seconds']);
        self::assertNull($runs['drill']['last']);
    }

    public function testBareMetalWithoutAConfigCoversEveryHost(): void
    {
        file_put_contents("{$this->dir}/pw", "placeholder\n");
        chmod("{$this->dir}/pw", 0600);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--repo' => '/srv/restic-repo', '--password-file' => "{$this->dir}/pw", '--json' => true]));

        $status = json_decode(Artisan::output(), true);
        self::assertIsArray($status);
        self::assertNull($status['host']);
        self::assertSame('e82340a7', $status['latest']['short_id']);
        self::assertSame(3, $status['snapshots'], 'safety snapshots are not backups');
    }

    public function testAHostWithoutBackups(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $this->writeConfig(), '--host' => 'web2.example.com']));
        self::assertStringContainsString("Latest snapshot: none yet\nSnapshots:       0 (4 in the repository)\n", Artisan::output());

        self::assertSame(ExitCode::FAILURE, Artisan::call('status', ['--config' => $this->writeConfig(), '--host' => 'web2.example.com', '--max-age' => '26']));
        self::assertStringContainsString('error No snapshot of web2.example.com in the repository', $this->logOutput());
    }

    public function testMaxAgeMakesItAMonitoringProbe(): void
    {
        $config = $this->writeConfig();

        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $config, '--max-age' => '26']));
        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $config, '--max-age' => '6']));
        self::assertSame(ExitCode::FAILURE, Artisan::call('status', ['--config' => $config, '--max-age' => '5.5']));
        self::assertStringContainsString('error The latest snapshot e82340a7 is 6.0 hours old, more than --max-age 5.5', $this->logOutput());
    }

    public function testAnInvalidMaxAgeIsAUsageError(): void
    {
        foreach (['abc', '0', '-3', '1h'] as $value) {
            self::assertSame(ExitCode::USAGE, Artisan::call('status', ['--config' => $this->writeConfig(), '--max-age' => $value]), $value);
            self::assertStringContainsString("error --max-age must be a number of hours greater than 0, not '{$value}'", $this->logOutput());
        }
        self::assertSame([], $this->restic->calls, 'checked before anything runs');
    }

    public function testAnUnreachableRepositoryIsExit1(): void
    {
        $this->restic->catConfig = [10, '', FakeRestic::fixture('no-repository-0.19.stderr')];

        self::assertSame(ExitCode::UNAVAILABLE, Artisan::call('status', ['--config' => $this->writeConfig()]));
        self::assertStringContainsString('error There is no restic repository at /srv/restic-repo', $this->logOutput());
        self::assertSame([], $this->pause->waits, 'a missing repository is not tried again');
    }

    public function testANetworkFailureWhileOpeningTheRepositoryIsTriedAgain(): void
    {
        $tries = 0;
        $this->restic->on['cat'] = static function () use (&$tries): array {
            return ++$tries < 3 ? [1, '', FakeRestic::fixture('sftp-unreachable-0.19.stderr')] : [0, "{\"version\": 2}\n", ''];
        };

        self::assertSame(ExitCode::SUCCESS, Artisan::call('status', ['--config' => $this->writeConfig()]));
        self::assertSame(3, $tries);
        self::assertCount(2, $this->pause->waits);
        self::assertStringContainsString('warning Opening the repository: Opening the restic repository failed (restic exit 1): subprocess ssh: ssh: connect to host backup.example.com port 2299: Connection refused', $this->logOutput());
        self::assertStringContainsString('(attempt 3 of 3)', $this->logOutput());
    }

    public function testARepositoryThatStaysUnreachableIsExit69AfterThreeTries(): void
    {
        $this->restic->catConfig = [1, '', FakeRestic::fixture('sftp-unreachable-0.19.stderr')];

        self::assertSame(ExitCode::UNAVAILABLE, Artisan::call('status', ['--config' => $this->writeConfig()]));
        self::assertCount(3, $this->restic->callsOf('cat'));
        self::assertCount(2, $this->pause->waits);
    }
}
