<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * GitHub release JSON and stand-in PHARs: PHP scripts that print "ArkHive <version>" for --version,
 * which is all the update smoke test runs.
 */
final class FakeReleases
{
    public const API = 'https://api.example.com/repos/mauriziofonte/arkhive';
    public const DOWNLOADS = 'https://downloads.example.com';

    public static function phar(string $version): string
    {
        return "<?php\n// stand-in ArkHive PHAR\nif (in_array('--version', \$argv, true)) {\n    echo \"ArkHive {$version}\\n\";\n    exit(0);\n}\nexit(1);\n";
    }

    /**
     * @return array<string, mixed>
     */
    public static function release(string $tag, bool $prerelease = false, ?int $size = null, bool $draft = false): array
    {
        $version = ltrim($tag, 'v');

        return [
            'tag_name' => $tag,
            'name' => "ArkHive {$tag}",
            'prerelease' => $prerelease,
            'draft' => $draft,
            'html_url' => "https://github.com/mauriziofonte/arkhive/releases/tag/{$tag}",
            'assets' => [
                ['name' => 'arkhive.phar', 'size' => $size ?? strlen(self::phar($version)), 'browser_download_url' => self::DOWNLOADS . "/{$tag}/arkhive.phar"],
                ['name' => 'arkhive.phar.sha256', 'size' => 81, 'browser_download_url' => self::DOWNLOADS . "/{$tag}/arkhive.phar.sha256"],
            ],
        ];
    }

    /**
     * Serves the release JSON and its assets; $body replaces the PHAR content (a tampered download).
     */
    public static function publish(FakeHttpClient $http, string $tag, bool $prerelease = false, ?string $body = null): void
    {
        $phar = self::phar(ltrim($tag, 'v'));
        $http->json(self::API . "/releases/tags/{$tag}", self::release($tag, $prerelease, $body === null ? null : strlen($body)));
        $http->on(self::DOWNLOADS . "/{$tag}/arkhive.phar", 200, $body ?? $phar);
        $http->on(self::DOWNLOADS . "/{$tag}/arkhive.phar.sha256", 200, hash('sha256', $phar) . "  arkhive.phar\n");
    }
}
