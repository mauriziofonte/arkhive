<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Update;

use Mfonte\Arkhive\Application\Port\HttpClient;
use Mfonte\Arkhive\Application\Port\HttpResponse;
use Mfonte\Arkhive\Domain\Update\Release;
use Mfonte\Arkhive\Domain\Update\Version;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\ProtocolException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * The GitHub REST API for the ArkHive releases (spec §9). The base URL can be overridden only by
 * the undocumented ARKHIVE_RELEASES_API, for the e2e fake server.
 */
final class ReleaseSource
{
    public const DEFAULT_API = 'https://api.github.com/repos/mauriziofonte/arkhive';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $api,
        private readonly string $userAgent,
        private readonly ?string $token = null,
    ) {
    }

    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(HttpClient $http, array $environment, string $currentVersion): self
    {
        $api = ($environment['ARKHIVE_RELEASES_API'] ?? '') !== '' ? $environment['ARKHIVE_RELEASES_API'] : self::DEFAULT_API;
        $token = ($environment['GITHUB_TOKEN'] ?? '') !== '' ? $environment['GITHUB_TOKEN'] : null;

        return new self($http, rtrim($api, '/'), "ArkHive/{$currentVersion}", $token);
    }

    /**
     * The latest stable release (GitHub excludes drafts and pre-releases).
     */
    public function latest(): Release
    {
        return Release::fromArray($this->json('/releases/latest', 'the latest release'));
    }

    /**
     * The newest release including pre-releases, drafts excluded.
     */
    public function newest(): Release
    {
        $list = $this->json('/releases?per_page=100', 'the list of releases');
        if (!is_array($list) || !array_is_list($list)) {
            throw new ProtocolException('The releases API answered something that is not a list of releases');
        }
        $newest = null;
        foreach ($list as $item) {
            try {
                $release = Release::fromArray($item);
            } catch (ArkhiveException) {
                continue;
            }
            if (!$release->draft && ($newest === null || Version::compare($release->version, $newest->version) > 0)) {
                $newest = $release;
            }
        }
        if ($newest === null) {
            throw new NoInputException('No release is published yet');
        }

        return $newest;
    }

    /**
     * A specific release, by tag with or without the "v".
     */
    public function tag(string $tag): Release
    {
        $version = Version::normalize($tag);
        if ($version === null) {
            throw new UsageException("{$tag} is not a release version (like v2.0.1)");
        }
        $response = $this->request('/releases/tags/' . rawurlencode('v' . $version));
        if ($response->status === 404) {
            $response = $this->request('/releases/tags/' . rawurlencode($version));
        }
        if ($response->status === 404) {
            throw new NoInputException("There is no release v{$version}", hint: 'The releases are listed at https://github.com/mauriziofonte/arkhive/releases');
        }

        return Release::fromArray($this->decode($response, "the release v{$version}"));
    }

    private function json(string $path, string $what): mixed
    {
        $response = $this->request($path);
        if ($response->status === 404) {
            throw new NoInputException("Not found on the releases API: {$what} (HTTP 404)", hint: 'The releases are listed at https://github.com/mauriziofonte/arkhive/releases');
        }

        return $this->decode($response, $what);
    }

    private function request(string $path): HttpResponse
    {
        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        return $this->http->get($this->api . $path, $headers);
    }

    private function decode(HttpResponse $response, string $what): mixed
    {
        if ($response->status === 403 || $response->status === 429) {
            throw new TemporaryException(
                "The GitHub API refused to list {$what} (HTTP {$response->status}, usually the rate limit for unauthenticated requests)",
                hint: $this->token === null ? 'Wait an hour, or set GITHUB_TOKEN to a token (no scopes needed) and run again' : 'Wait for the rate limit to reset, or check GITHUB_TOKEN',
            );
        }
        if (!$response->ok()) {
            throw new UnavailableException("The releases API answered HTTP {$response->status} for {$what}");
        }
        try {
            return json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolException("The releases API answered invalid JSON for {$what}: {$e->getMessage()}");
        }
    }
}
