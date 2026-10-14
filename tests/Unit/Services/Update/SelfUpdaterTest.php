<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Update;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Context\ExecutionContext;
use Mfonte\Arkhive\Services\Context\LogFormat;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Output\LogStream;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Signals;
use Mfonte\Arkhive\Services\Process\SystemProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\Update\ReleaseSource;
use Mfonte\Arkhive\Services\Update\SelfUpdater;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeReleases;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;

/**
 * spec §9: verification before anything changes, the major guard, the atomic swap and --rollback.
 * The HTTP layer is faked; the smoke test really runs PHP on the downloaded file.
 */
final class SelfUpdaterTest extends TestCase
{
    use TempDir;

    private string $dir;
    private string $phar;
    private FakeHttpClient $http;
    private Cleanup $cleanup;
    private LogStream $stream;
    private Dependencies $dependencies;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/bin");
        $this->phar = "{$this->dir}/bin/arkhive";
        file_put_contents($this->phar, FakeReleases::phar('2.0.0'));
        chmod($this->phar, 0751);
        $this->http = new FakeHttpClient();
        $this->cleanup = new Cleanup();
        $this->stream = LogStream::memory();
        $this->dependencies = FakeBinaries::dependencies("{$this->dir}/tools", []);
    }

    protected function tearDown(): void
    {
        $this->cleanup->runAll();
    }

    private function updater(string $current = '2.0.0', ?string $phar = null): SelfUpdater
    {
        return new SelfUpdater(
            ReleaseSource::fromEnvironment($this->http, ['ARKHIVE_RELEASES_API' => FakeReleases::API], $current),
            $this->http,
            new SystemProcessRunner(new Signals()),
            $this->dependencies,
            $this->cleanup,
            $current,
            $phar ?? $this->phar,
        );
    }

    private function logger(): Logger
    {
        return new Logger(new ExecutionContext(false, false, false, LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock());
    }

    public function testInstallReplacesThePharAndKeepsThePreviousOne(): void
    {
        FakeReleases::publish($this->http, 'v2.0.1');
        $before = (string) file_get_contents($this->phar);
        $updater = $this->updater();
        $release = $updater->target('v2.0.1', false);
        self::assertTrue($updater->isNewer($release));
        self::assertFalse($updater->crossesMajor($release));

        $result = $updater->install($release, false, $this->logger());

        self::assertSame(['from' => '2.0.0', 'to' => '2.0.1', 'path' => $this->phar, 'backup' => "{$this->phar}.bak-2.0.0", 'provenance' => 'not verified (gh not installed)'], $result->toArray());
        self::assertSame(FakeReleases::phar('2.0.1'), file_get_contents($this->phar));
        self::assertSame($before, file_get_contents("{$this->phar}.bak-2.0.0"));
        self::assertSame('0751', sprintf('%04o', fileperms($this->phar) & 0777), 'the mode of the replaced file is kept');
        self::assertSame(["{$this->phar}", "{$this->phar}.bak-2.0.0"], $this->files());
        self::assertSame(0, $this->cleanup->pending());
        self::assertStringContainsString('notice Build provenance was not verified (gh is not installed); the sha256 matched', $this->stream->contents());
        self::assertSame(['User-Agent' => 'ArkHive/2.0.0'], $this->http->requests[1][1], 'downloads carry no token');
    }

    public function testLatestAndNewestPickTheRelease(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v2.0.1'));
        $this->http->json(FakeReleases::API . '/releases?per_page=100', [FakeReleases::release('v2.0.1'), FakeReleases::release('v2.1.0-rc.1', true)]);

        self::assertSame('v2.0.1', $this->updater()->target(null, false)->tag);
        self::assertSame('v2.1.0-rc.1', $this->updater()->target(null, true)->tag);
        self::assertFalse($this->updater('2.0.1')->isNewer($this->updater()->target(null, false)));
    }

    public function testAChecksumMismatchChangesNothing(): void
    {
        FakeReleases::publish($this->http, 'v2.0.1', body: FakeReleases::phar('6.6.6'));
        $before = (string) file_get_contents($this->phar);

        try {
            $this->updater()->install($this->updater()->target('v2.0.1', false), false, $this->logger());
            self::fail('a tampered download was installed');
        } catch (ArkhiveException $e) {
            self::assertStringStartsWith('The downloaded v2.0.1 PHAR does not match its published sha256 (expected ' . hash('sha256', FakeReleases::phar('2.0.1')) . ', got ' . hash('sha256', FakeReleases::phar('6.6.6')) . '): not installed', $e->getMessage());
        }
        $this->cleanup->runAll();
        self::assertSame($before, file_get_contents($this->phar));
        self::assertSame([$this->phar], $this->files(), 'no download and no backup left behind');
    }

    public function testTheMajorGuard(): void
    {
        FakeReleases::publish($this->http, 'v3.0.0');
        $updater = $this->updater();
        $release = $updater->target('v3.0.0', false);
        self::assertTrue($updater->crossesMajor($release));

        try {
            $updater->install($release, false, $this->logger());
            self::fail('a major version was crossed');
        } catch (RefusedException $e) {
            self::assertSame('v3.0.0 is a different major version than the running 2.0.0: its configuration is not compatible', $e->getMessage());
            self::assertSame('Read the migration notes first (https://github.com/mauriziofonte/arkhive#migrating-from-1x), then run again with --allow-major', $e->hint());
        }
        self::assertSame([], $this->http->urls() === [] ? [] : array_values(array_filter($this->http->urls(), static fn (string $url): bool => str_starts_with($url, FakeReleases::DOWNLOADS))), 'nothing downloaded');

        self::assertSame('3.0.0', $updater->install($release, true, $this->logger())->to);
    }

    public function testASmokeTestFailureChangesNothing(): void
    {
        // the published file is consistent with its checksum but reports another version
        $phar = FakeReleases::phar('2.0.0');
        $this->http->json(FakeReleases::API . '/releases/tags/v2.0.1', FakeReleases::release('v2.0.1'));
        $this->http->on(FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar', 200, $phar);
        $this->http->on(FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar.sha256', 200, hash('sha256', $phar) . "\n");

        $this->expectExceptionMessageMatches('/^\.arkhive\.download-[0-9a-f]{8} --version printed "ArkHive 2\.0\.0" instead of 2\.0\.1: not installed$/');
        try {
            $this->updater()->install($this->updater()->target('v2.0.1', false), false, $this->logger());
        } finally {
            $this->cleanup->runAll();
            self::assertSame([$this->phar], $this->files());
        }
    }

    public function testDownloadProblems(): void
    {
        FakeReleases::publish($this->http, 'v2.0.1');
        $this->http->on(FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar.sha256', 200, "not a checksum\n");
        $this->assertInstallFails('v2.0.1', 'The checksum file of v2.0.1 does not start with a sha256: not installed');

        $this->http->on(FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar.sha256', 500, '');
        $this->assertInstallFails('v2.0.1', 'Downloading the checksum of v2.0.1 failed (HTTP 500)');

        FakeReleases::publish($this->http, 'v2.0.1');
        $this->http->on(FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar', 503, 'busy');
        $this->assertInstallFails('v2.0.1', 'Downloading ' . FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar failed (HTTP 503)');

        // larger than the size the release declares
        FakeReleases::publish($this->http, 'v2.0.1');
        $release = FakeReleases::release('v2.0.1', false, 10);
        $this->http->json(FakeReleases::API . '/releases/tags/v2.0.1', $release);
        $this->assertInstallFails('v2.0.1', 'The download of ' . FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar is larger than the expected 10 bytes: stopped');

        $this->http->json(FakeReleases::API . '/releases/tags/v2.0.1', ['tag_name' => 'v2.0.1', 'assets' => []]);
        $this->assertInstallFails('v2.0.1', 'The release v2.0.1 has no arkhive.phar asset: it cannot be installed');
        self::assertSame([$this->phar], $this->files());
    }

    public function testProvenanceIsVerifiedWithGhWhenInstalled(): void
    {
        $this->dependencies = FakeBinaries::dependencies("{$this->dir}/tools", ['gh']);
        file_put_contents("{$this->dir}/tools/gh", "#!/bin/sh\necho \"\$@\" > " . escapeshellarg("{$this->dir}/gh.args") . "\nexit 0\n");
        FakeReleases::publish($this->http, 'v2.0.1');

        $result = $this->updater()->install($this->updater()->target('v2.0.1', false), false, $this->logger());

        self::assertSame('verified', $result->provenance);
        self::assertMatchesRegularExpression('#^attestation verify ' . preg_quote($this->dir, '#') . '/bin/\.arkhive\.download-[0-9a-f]{8} --repo mauriziofonte/arkhive$#', trim((string) file_get_contents("{$this->dir}/gh.args")));
        self::assertStringContainsString('info Build provenance verified with gh attestation verify', $this->stream->contents());
    }

    public function testAProvenanceMismatchStopsTheUpdate(): void
    {
        $this->dependencies = FakeBinaries::dependencies("{$this->dir}/tools", ['gh']);
        file_put_contents("{$this->dir}/tools/gh", "#!/bin/sh\necho 'X verification failed: no attestation matched' >&2\nexit 1\n");
        FakeReleases::publish($this->http, 'v2.0.1');

        $this->assertInstallFails('v2.0.1', 'Verifying the build provenance failed (gh exit 1): X verification failed: no attestation matched: not installed');
        self::assertSame([$this->phar], $this->files());
    }

    public function testGhWithoutALoginIsReportedNotFatal(): void
    {
        $this->dependencies = FakeBinaries::dependencies("{$this->dir}/tools", ['gh']);
        file_put_contents("{$this->dir}/tools/gh", "#!/bin/sh\necho 'To get started with GitHub CLI, please run:  gh auth login' >&2\nexit 4\n");
        FakeReleases::publish($this->http, 'v2.0.1');

        $result = $this->updater()->install($this->updater()->target('v2.0.1', false), false, $this->logger());

        self::assertSame('not verified (gh not logged in)', $result->provenance);
        self::assertStringContainsString('gh is not logged in: set GH_TOKEN or run gh auth login', $this->stream->contents());
    }

    public function testRollbackSwapsBackAndForth(): void
    {
        FakeReleases::publish($this->http, 'v2.0.1');
        $this->updater()->install($this->updater()->target('v2.0.1', false), false, $this->logger());

        $back = $this->updater('2.0.1')->rollback($this->logger());

        self::assertSame(['from' => '2.0.1', 'to' => '2.0.0', 'path' => $this->phar, 'backup' => "{$this->phar}.bak-2.0.1", 'provenance' => 'not applicable'], $back->toArray());
        self::assertSame(FakeReleases::phar('2.0.0'), file_get_contents($this->phar));
        self::assertSame([$this->phar, "{$this->phar}.bak-2.0.1"], $this->files());

        $this->updater('2.0.0')->rollback($this->logger());
        self::assertSame(FakeReleases::phar('2.0.1'), file_get_contents($this->phar));
        self::assertSame([$this->phar, "{$this->phar}.bak-2.0.0"], $this->files());
    }

    public function testOnlyTheLastPreviousVersionIsKept(): void
    {
        file_put_contents("{$this->phar}.bak-1.9.0", FakeReleases::phar('1.9.0'));
        file_put_contents("{$this->phar}.bak-notes", 'not a version: left alone');
        FakeReleases::publish($this->http, 'v2.0.1');

        $this->updater()->install($this->updater()->target('v2.0.1', false), false, $this->logger());

        self::assertSame([$this->phar, "{$this->phar}.bak-2.0.0", "{$this->phar}.bak-notes"], $this->files());
    }

    public function testRollbackProblems(): void
    {
        try {
            $this->updater()->rollback($this->logger());
            self::fail('a rollback without a kept binary ran');
        } catch (ArkhiveException $e) {
            self::assertSame("There is no previous version next to {$this->phar} (no {$this->phar}.bak-*): nothing to roll back to", $e->getMessage());
            self::assertSame('Install a specific release with: arkhive self-update <tag>', $e->hint());
        }

        copy($this->phar, "{$this->phar}.bak-2.0.0");
        try {
            $this->updater()->rollback($this->logger());
            self::fail('a rollback to the same version ran');
        } catch (ArkhiveException $e) {
            self::assertSame("The kept binary {$this->phar}.bak-2.0.0 is the running version 2.0.0: nothing to roll back to", $e->getMessage());
        }
        unlink("{$this->phar}.bak-2.0.0");

        // a kept file that does not run is not put back
        file_put_contents("{$this->phar}.bak-1.9.0", "<?php echo 'broken';\n");
        $this->expectExceptionMessage('arkhive.bak-1.9.0 --version printed "broken" instead of 1.9.0: not installed');
        $this->updater()->rollback($this->logger());
    }

    public function testRefusedFromSource(): void
    {
        FakeReleases::publish($this->http, 'v2.0.1');
        foreach ([fn () => $this->updater(phar: '')->install($this->updater()->target('v2.0.1', false), false, $this->logger()), fn () => $this->updater(phar: '')->rollback($this->logger())] as $call) {
            try {
                $call();
                self::fail('a source checkout was updated');
            } catch (RefusedException $e) {
                self::assertSame('self-update works only for the PHAR installation; this ArkHive runs from source', $e->getMessage());
                self::assertSame(ExitCode::REFUSED, $e->exitCode());
            }
        }
    }

    public function testAnUnwritableDirectoryIsRefusedBeforeDownloading(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write any directory');
        }
        FakeReleases::publish($this->http, 'v2.0.1');
        chmod("{$this->dir}/bin", 0555);
        try {
            $this->assertInstallFails('v2.0.1', "{$this->dir}/bin is not writable by this user, so {$this->phar} cannot be replaced: nothing was changed");
            self::assertNotContains(FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar', $this->http->urls());
        } finally {
            chmod("{$this->dir}/bin", 0755);
        }
    }

    private function assertInstallFails(string $tag, string $message): void
    {
        try {
            $this->updater()->install($this->updater()->target($tag, false), false, $this->logger());
            self::fail("the install worked: {$message}");
        } catch (ArkhiveException $e) {
            self::assertSame($message, $e->getMessage());
        } finally {
            $this->cleanup->runAll();
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob("{$this->dir}/bin/{,.}*", GLOB_BRACE) ?: [];
        $files = array_values(array_filter($files, static fn (string $f): bool => !in_array(basename($f), ['.', '..'], true)));
        sort($files);

        return $files;
    }
}
