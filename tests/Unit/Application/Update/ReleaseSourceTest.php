<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Update;

use Mfonte\Arkhive\Application\Update\ReleaseSource;
use Mfonte\Arkhive\Domain\Update\Release;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeReleases;

/**
 * spec §9: the GitHub REST API calls, parsing and errors (HTTP layer faked).
 */
final class ReleaseSourceTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    private function source(array $environment = []): ReleaseSource
    {
        return ReleaseSource::fromEnvironment($this->http, ['ARKHIVE_RELEASES_API' => FakeReleases::API . '/', ...$environment], '2.0.0');
    }

    public function testLatestSendsTheDocumentedHeaders(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v2.0.1'));

        $release = $this->source()->latest();

        self::assertSame(['v2.0.1', '2.0.1', false, false], [$release->tag, $release->version, $release->prerelease, $release->draft]);
        self::assertSame(FakeReleases::DOWNLOADS . '/v2.0.1/arkhive.phar', $release->asset('arkhive.phar')['url']);
        [$url, $headers] = $this->http->requests[0];
        self::assertSame(FakeReleases::API . '/releases/latest', $url);
        self::assertSame(['User-Agent' => 'ArkHive/2.0.0', 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'], $headers);
    }

    public function testTheTokenIsSentOnlyWhenSet(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', FakeReleases::release('v2.0.1'));
        $this->source(['GITHUB_TOKEN' => 'placeholder-token'])->latest();

        self::assertSame('Bearer placeholder-token', $this->http->requests[0][1]['Authorization']);
    }

    public function testTheDefaultApiIsGithub(): void
    {
        $source = ReleaseSource::fromEnvironment($this->http, [], '2.0.0');
        try {
            $source->latest();
        } catch (ArkhiveException) {
        }
        self::assertSame('https://api.github.com/repos/mauriziofonte/arkhive/releases/latest', $this->http->urls()[0]);
    }

    public function testNewestIncludesPrereleasesAndSkipsDraftsAndJunk(): void
    {
        $this->http->json(FakeReleases::API . '/releases?per_page=100', [
            FakeReleases::release('v2.0.0'),
            FakeReleases::release('v2.1.0-rc.1', true),
            FakeReleases::release('v2.2.0', false, null, true),
            ['tag_name' => 'nightly'],
            'not a release',
            FakeReleases::release('v1.7.1'),
        ]);

        self::assertSame('v2.1.0-rc.1', $this->source()->newest()->tag);
    }

    public function testNewestWithoutReleases(): void
    {
        $this->http->json(FakeReleases::API . '/releases?per_page=100', []);
        $this->expectExceptionMessage('No release is published yet');
        $this->source()->newest();
    }

    public function testNewestNeedsAList(): void
    {
        $this->http->json(FakeReleases::API . '/releases?per_page=100', ['message' => 'x']);
        $this->expectExceptionMessage('The releases API answered something that is not a list of releases');
        $this->source()->newest();
    }

    public function testTagWithOrWithoutTheV(): void
    {
        $this->http->json(FakeReleases::API . '/releases/tags/v2.0.1', FakeReleases::release('v2.0.1'));
        self::assertSame('v2.0.1', $this->source()->tag('2.0.1')->tag);

        // a tag published without the "v"
        $this->http->json(FakeReleases::API . '/releases/tags/2.0.2', FakeReleases::release('2.0.2'));
        self::assertSame('2.0.2', $this->source()->tag('v2.0.2')->tag);
        self::assertContains(FakeReleases::API . '/releases/tags/v2.0.2', $this->http->urls());
    }

    public function testUnknownAndInvalidTags(): void
    {
        try {
            $this->source()->tag('v9.9.9');
            self::fail('an unknown tag was found');
        } catch (ArkhiveException $e) {
            self::assertSame('There is no release v9.9.9', $e->getMessage());
            self::assertSame(ExitCode::NO_INPUT, $e->exitCode());
        }

        try {
            $this->source()->tag('latest-ish');
            self::fail('an invalid tag was accepted');
        } catch (ArkhiveException $e) {
            self::assertSame('latest-ish is not a release version (like v2.0.1)', $e->getMessage());
            self::assertSame(ExitCode::USAGE, $e->exitCode());
        }
    }

    public function testRateLimitPointsAtTheToken(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', ['message' => 'API rate limit exceeded'], 403);
        try {
            $this->source()->latest();
            self::fail('a 403 was accepted');
        } catch (ArkhiveException $e) {
            self::assertSame('The GitHub API refused to list the latest release (HTTP 403, usually the rate limit for unauthenticated requests)', $e->getMessage());
            self::assertSame('Wait an hour, or set GITHUB_TOKEN to a token (no scopes needed) and run again', $e->hint());
        }

        $this->http->json(FakeReleases::API . '/releases/latest', ['message' => 'slow down'], 429);
        try {
            $this->source(['GITHUB_TOKEN' => 'placeholder-token'])->latest();
            self::fail('a 429 was accepted');
        } catch (ArkhiveException $e) {
            self::assertSame('Wait for the rate limit to reset, or check GITHUB_TOKEN', $e->hint());
        }
    }

    public function testServerErrorsAndBrokenAnswers(): void
    {
        $this->http->json(FakeReleases::API . '/releases/latest', 'oops', 502);
        $this->assertFails(fn () => $this->source()->latest(), 'The releases API answered HTTP 502 for the latest release');

        $this->http->json(FakeReleases::API . '/releases/latest', '{"tag_name": ');
        $this->assertFails(fn () => $this->source()->latest(), 'The releases API answered invalid JSON for the latest release: Syntax error');

        $this->http->json(FakeReleases::API . '/releases/latest', ['message' => 'Not Found'], 404);
        $this->assertFails(fn () => $this->source()->latest(), 'Not found on the releases API: the latest release (HTTP 404)');

        $this->http->json(FakeReleases::API . '/releases/latest', ['tag_name' => 'nightly']);
        $this->assertFails(fn () => $this->source()->latest(), 'The release tag nightly is not a semantic version');

        $this->http->json(FakeReleases::API . '/releases/latest', ['id' => 1]);
        $this->assertFails(fn () => $this->source()->latest(), 'The releases API answered something that is not a release');
    }

    public function testAssetsAreParsedDefensively(): void
    {
        $release = Release::fromArray(['tag_name' => 'v2.0.1', 'assets' => [['name' => 'arkhive.phar', 'browser_download_url' => 'https://downloads.example.com/a'], ['name' => 1], 'x']]);

        self::assertSame(['arkhive.phar' => ['url' => 'https://downloads.example.com/a', 'size' => 0]], $release->assets);
        self::assertSame('', $release->htmlUrl);
        $this->expectExceptionMessage('The release v2.0.1 has no arkhive.phar.sha256 asset: it cannot be installed');
        $release->asset('arkhive.phar.sha256');
    }

    private function assertFails(callable $call, string $message): void
    {
        try {
            $call();
            self::fail("no failure: {$message}");
        } catch (ArkhiveException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }
}
