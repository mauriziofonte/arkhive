<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Commands\SelfUpdateCommand;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\Update\HttpClient;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeReleases;
use Tests\Support\TempDir;
use Tests\TestCase;

/**
 * spec §9 self-update options and exit codes, and the hidden self-upgrade alias (spec §6).
 */
final class SelfUpdateCommandTest extends TestCase
{
    use TempDir;

    private FakeHttpClient $http;
    private string $phar;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = $this->tempDir();
        $this->phar = "{$dir}/arkhive";
        file_put_contents($this->phar, FakeReleases::phar('2.0.0'));
        SelfUpdateCommand::$pharPath = $this->phar;
        config()->set('app.version', '2.0.0');
        $this->http = new FakeHttpClient();
        $this->app->instance(HttpClient::class, $this->http);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$dir}/bin", []));
        $this->terminal->env['ARKHIVE_RELEASES_API'] = FakeReleases::API;
    }

    protected function tearDown(): void
    {
        SelfUpdateCommand::$pharPath = null;
        parent::tearDown();
    }

    public function testCheckExitsTenWhenAnUpdateExists(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v2.0.1'));

        self::assertSame(ExitCode::UPDATE_AVAILABLE, Artisan::call('self-update', ['--check' => true, '--json' => true]));
        self::assertSame(['current' => '2.0.0', 'latest' => 'v2.0.1', 'update_available' => true, 'major' => false], json_decode(Artisan::output(), true));
        self::assertStringContainsString('notice An update is available: 2.0.0 → v2.0.1 (run arkhive self-update)', $this->logOutput());
        self::assertSame(FakeReleases::phar('2.0.0'), file_get_contents($this->phar), '--check changes nothing');
    }

    public function testCheckWhenCurrent(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v2.0.0'));

        self::assertSame(ExitCode::SUCCESS, Artisan::call('self-update', ['--check' => true]));
        self::assertStringContainsString('success ArkHive 2.0.0 is up to date (latest release: v2.0.0)', $this->logOutput());
    }

    public function testCheckNamesAMajorUpdate(): void
    {
        $this->http->json(FakeReleases::API . '/releases?per_page=100', [FakeReleases::release('v3.0.0-rc.1', true)]);

        self::assertSame(ExitCode::UPDATE_AVAILABLE, Artisan::call('self-update', ['--check' => true, '--pre' => true]));
        self::assertStringContainsString('(a new major version: read https://github.com/mauriziofonte/arkhive#migrating-from-1x, then run arkhive self-update --allow-major)', $this->logOutput());
    }

    public function testUpdateToTheLatest(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v2.0.1'));
        FakeReleases::publish($this->http, 'v2.0.1');

        self::assertSame(ExitCode::SUCCESS, Artisan::call('self-update', ['--json' => true]), $this->logOutput());
        self::assertSame(FakeReleases::phar('2.0.1'), file_get_contents($this->phar));
        $json = json_decode(Artisan::output(), true);
        self::assertSame(['2.0.0', '2.0.1', true], [$json['from'], $json['to'], $json['updated']]);
        self::assertStringContainsString("success Updated {$this->phar} from 2.0.0 to 2.0.1; the previous binary is kept as {$this->phar}.bak-2.0.0 (undo with: arkhive self-update --rollback)", $this->logOutput());
    }

    public function testAlreadyCurrentChangesNothing(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v1.9.0'));

        self::assertSame(ExitCode::SUCCESS, Artisan::call('self-update'));
        self::assertStringContainsString('success ArkHive 2.0.0 is up to date (latest release: v1.9.0)', $this->logOutput());
        self::assertFileDoesNotExist("{$this->phar}.bak-2.0.0");
    }

    public function testASpecificOlderReleaseIsADowngradeWithAWarning(): void
    {
        FakeReleases::publish($this->http, 'v2.0.0-rc.1');

        self::assertSame(ExitCode::SUCCESS, Artisan::call('self-update', ['release' => '2.0.0-rc.1']), $this->logOutput());
        self::assertStringContainsString('warning v2.0.0-rc.1 is older than the running 2.0.0: downgrading as requested', $this->logOutput());
        self::assertSame(FakeReleases::phar('2.0.0-rc.1'), file_get_contents($this->phar));
    }

    public function testTheMajorGuardExitsFour(): void
    {
        FakeReleases::publish($this->http, 'v1.7.1');

        self::assertSame(ExitCode::REFUSED, Artisan::call('self-update', ['release' => 'v1.7.1']));
        self::assertStringContainsString('error v1.7.1 is a different major version than the running 2.0.0', $this->logOutput());

        self::assertSame(ExitCode::SUCCESS, Artisan::call('self-update', ['release' => 'v1.7.1', '--allow-major' => true]), $this->logOutput());
        self::assertSame(FakeReleases::phar('1.7.1'), file_get_contents($this->phar));
    }

    public function testRollback(): void
    {
        file_put_contents("{$this->phar}.bak-1.9.0", FakeReleases::phar('1.9.0'));

        self::assertSame(ExitCode::SUCCESS, Artisan::call('self-update', ['--rollback' => true, '--json' => true]), $this->logOutput());
        self::assertSame(FakeReleases::phar('1.9.0'), file_get_contents($this->phar));
        self::assertSame('1.9.0', json_decode(Artisan::output(), true)['to']);
        self::assertStringContainsString("success Rolled back {$this->phar} from 2.0.0 to 1.9.0; 2.0.0 is kept as {$this->phar}.bak-2.0.0", $this->logOutput());
        self::assertSame([], $this->http->requests, 'a rollback needs no network');
    }

    public function testRollbackCombinedWithOtherOptionsIsAUsageError(): void
    {
        self::assertSame(ExitCode::USAGE, Artisan::call('self-update', ['--rollback' => true, '--check' => true]));
        self::assertSame(ExitCode::USAGE, Artisan::call('self-update', ['--rollback' => true, 'release' => 'v2.0.1']));
        self::assertStringContainsString('error --rollback cannot be combined with --check, --pre or a release', $this->logOutput());
    }

    public function testNetworkFailuresExitOne(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', ['message' => 'rate limited'], 403);

        self::assertSame(ExitCode::FAILURE, Artisan::call('self-update', ['--check' => true]));
        self::assertStringContainsString('notice Wait an hour, or set GITHUB_TOKEN', $this->logOutput());
    }

    public function testSelfUpgradeIsAHiddenDeprecatedAlias(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v2.0.0'));

        self::assertSame(ExitCode::SUCCESS, Artisan::call('self-upgrade'));
        self::assertStringContainsString('warning self-upgrade is deprecated and will be removed: use arkhive self-update', $this->logOutput());
        self::assertStringContainsString('success ArkHive 2.0.0 is up to date', $this->logOutput());

        Artisan::call('list');
        $list = Artisan::output();
        self::assertStringContainsString('self-update', $list);
        self::assertStringNotContainsString('self-upgrade', $list);
    }
}
