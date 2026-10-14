<?php

declare(strict_types=1);

namespace Tests\e2e;

use Mfonte\Arkhive\ExitCode;
use RuntimeException;

/**
 * spec §13.2 step 13: self-update of an installed copy of the PHAR against the fake releases
 * server (a static HTTP server, selected through ARKHIVE_RELEASES_API): --check, update, checksum
 * mismatch refused, major guard, --rollback. The "new" releases are the PHAR under test with
 * another version written into it, so the smoke test runs the real application.
 */
final class SelfUpdateTest extends E2eTestCase
{
    private string $dir;
    private string $installed;
    private string $current;
    private string $api;
    private string $releases;
    private string $downloads;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/bin");
        $this->installed = "{$this->dir}/bin/arkhive";
        copy(self::env('E2E_PHAR'), $this->installed);
        chmod($this->installed, 0755);
        $this->current = self::version($this->installed);

        // each test publishes under its own prefix of the shared static server
        $prefix = 'e2e-' . bin2hex(random_bytes(4));
        $this->releases = self::env('E2E_RELEASES_DIR') . "/{$prefix}";
        $this->api = self::env('E2E_RELEASES_URL') . "/{$prefix}/repos/mauriziofonte/arkhive";
        $this->downloads = self::env('E2E_RELEASES_URL') . "/{$prefix}/download";
        mkdir("{$this->releases}/repos/mauriziofonte/arkhive/releases/tags", 0755, true);
        mkdir("{$this->releases}/download", 0755, true);

        // a fake gh first in PATH: provenance "verified", with the arguments recorded
        mkdir("{$this->dir}/fakebin");
        file_put_contents("{$this->dir}/fakebin/gh", "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg("{$this->dir}/gh.log") . "\nexit 0\n");
        chmod("{$this->dir}/fakebin/gh", 0755);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->releases);
    }

    public function testCheckUpdateAndRollback(): void
    {
        $next = $this->bump(minor: true);
        $this->publish([$next], latest: $next);

        $check = $this->selfUpdate(['--check', '--json']);
        self::assertSame(ExitCode::UPDATE_AVAILABLE, $check->exitCode, $check->output());
        self::assertSame(['current' => $this->current, 'latest' => "v{$next}", 'update_available' => true, 'major' => false], json_decode($check->stdout, true));
        self::assertSame($this->current, self::version($this->installed), '--check changes nothing');

        $update = $this->selfUpdate([]);
        self::assertSame(ExitCode::SUCCESS, $update->exitCode, $update->output());
        self::assertSame($next, self::version($this->installed));
        self::assertSame($this->current, self::version("{$this->installed}.bak-{$this->current}"));
        self::assertSame('0755', sprintf('%04o', fileperms($this->installed) & 0777));
        self::assertMatchesRegularExpression('#^attestation verify ' . preg_quote($this->dir, '#') . '/bin/\.arkhive\.download-[0-9a-f]{8} --repo mauriziofonte/arkhive$#m', (string) file_get_contents("{$this->dir}/gh.log"));
        self::assertStringContainsString('Build provenance verified', $update->stderr);
        self::assertStringNotContainsString("\e[", $update->stderr, 'no colour without a terminal');

        $again = $this->selfUpdate(['--check']);
        self::assertSame(ExitCode::SUCCESS, $again->exitCode, $again->output());

        // the updated binary rolls itself back
        $rollback = $this->selfUpdate(['--rollback']);
        self::assertSame(ExitCode::SUCCESS, $rollback->exitCode, $rollback->output());
        self::assertSame($this->current, self::version($this->installed));
        self::assertSame($next, self::version("{$this->installed}.bak-{$next}"));
        self::assertSame(hash_file('sha256', self::env('E2E_PHAR')), hash_file('sha256', $this->installed), 'the original binary is back, byte for byte');
    }

    public function testPrereleasesOnlyWithPre(): void
    {
        $stable = $this->bump(minor: true);
        $rc = $this->bump(minor: true, extra: 1) . '-rc.1';
        $this->publish([$stable, $rc], latest: $stable);

        $plain = $this->selfUpdate(['--check', '--json']);
        self::assertSame("v{$stable}", json_decode($plain->stdout, true)['latest'], $plain->output());

        $pre = $this->selfUpdate(['--check', '--pre', '--json']);
        self::assertSame("v{$rc}", json_decode($pre->stdout, true)['latest'], $pre->output());

        $update = $this->selfUpdate(['--pre']);
        self::assertSame(ExitCode::SUCCESS, $update->exitCode, $update->output());
        self::assertSame($rc, self::version($this->installed));
    }

    public function testAChecksumMismatchIsRefusedAndChangesNothing(): void
    {
        $next = $this->bump(minor: true);
        $this->publish([$next], latest: $next);
        file_put_contents("{$this->releases}/download/v{$next}/arkhive.phar.sha256", str_repeat('0', 64) . "  arkhive.phar\n");
        $before = hash_file('sha256', $this->installed);

        $result = $this->selfUpdate([]);

        self::assertSame(ExitCode::FAILURE, $result->exitCode, $result->output());
        self::assertStringContainsString("error The downloaded v{$next} PHAR does not match its published sha256", $result->stderr);
        self::assertSame($before, hash_file('sha256', $this->installed));
        self::assertSame(['arkhive'], array_values(array_diff(scandir("{$this->dir}/bin") ?: [], ['.', '..'])), 'no download, no backup');
    }

    public function testTheMajorGuard(): void
    {
        $major = (string) ((int) explode('.', $this->current)[0] + 1) . '.0.0';
        $this->publish([$major], latest: $major);

        $check = $this->selfUpdate(['--check']);
        self::assertSame(ExitCode::UPDATE_AVAILABLE, $check->exitCode, $check->output());
        self::assertStringContainsString('a new major version', $check->stderr);

        $refused = $this->selfUpdate([]);
        self::assertSame(ExitCode::REFUSED, $refused->exitCode, $refused->output());
        self::assertStringContainsString("is a different major version than the running {$this->current}", $refused->stderr);
        self::assertSame($this->current, self::version($this->installed));

        $allowed = $this->selfUpdate(['--allow-major']);
        self::assertSame(ExitCode::SUCCESS, $allowed->exitCode, $allowed->output());
        self::assertSame($major, self::version($this->installed));
    }

    public function testASpecificReleaseAndTheDeprecatedAlias(): void
    {
        $next = $this->bump(minor: true);
        $later = $this->bump(minor: true, extra: 1);
        $this->publish([$next, $later], latest: $later);

        $result = self::exec([PHP_BINARY, $this->installed, 'self-upgrade', "v{$next}"], $this->updateEnv());
        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->output());
        self::assertStringContainsString('warning self-upgrade is deprecated and will be removed: use arkhive self-update', $result->stderr);
        self::assertSame($next, self::version($this->installed));
    }

    /**
     * @param list<string> $args
     */
    private function selfUpdate(array $args): \Tests\Support\CliResult
    {
        return self::exec([PHP_BINARY, $this->installed, 'self-update', ...$args], $this->updateEnv());
    }

    /**
     * @return array<string, string>
     */
    private function updateEnv(): array
    {
        return ['ARKHIVE_RELEASES_API' => $this->api, 'PATH' => "{$this->dir}/fakebin:" . self::path()];
    }

    /**
     * A version above the current one: the next minor (plus $extra), or the next patch.
     */
    private function bump(bool $minor, int $extra = 0): string
    {
        [$major, $min, $patch] = array_map('intval', explode('.', (string) preg_replace('/-.*$/', '', $this->current)));

        return $minor ? sprintf('%d.%d.0', $major, $min + 1 + $extra) : sprintf('%d.%d.%d', $major, $min, $patch + 1 + $extra);
    }

    /**
     * Writes the API JSON (latest, tags, the list) and the assets of each version.
     *
     * @param list<string> $versions
     */
    private function publish(array $versions, string $latest): void
    {
        $base = "{$this->releases}/repos/mauriziofonte/arkhive/releases";
        $list = [];
        foreach ($versions as $version) {
            $tag = "v{$version}";
            $assets = "{$this->releases}/download/{$tag}";
            mkdir($assets, 0755, true);
            $this->buildPhar($version, "{$assets}/arkhive.phar");
            file_put_contents("{$assets}/arkhive.phar.sha256", hash_file('sha256', "{$assets}/arkhive.phar") . "  arkhive.phar\n");
            $release = [
                'tag_name' => $tag,
                'prerelease' => str_contains($version, '-'),
                'draft' => false,
                'html_url' => "https://github.com/mauriziofonte/arkhive/releases/tag/{$tag}",
                'assets' => [
                    ['name' => 'arkhive.phar', 'size' => filesize("{$assets}/arkhive.phar"), 'browser_download_url' => "{$this->downloads}/{$tag}/arkhive.phar"],
                    ['name' => 'arkhive.phar.sha256', 'size' => filesize("{$assets}/arkhive.phar.sha256"), 'browser_download_url' => "{$this->downloads}/{$tag}/arkhive.phar.sha256"],
                ],
            ];
            file_put_contents("{$base}/tags/{$tag}", json_encode($release, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($version === $latest) {
                file_put_contents("{$base}/latest", json_encode($release, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
            $list[] = $release;
        }
        // GET /releases: the static server answers a directory with its index.html
        file_put_contents("{$base}/index.html", json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The PHAR under test with $version written into config/app.php (the archive is re-signed).
     */
    private function buildPhar(string $version, string $destination): void
    {
        copy(self::env('E2E_PHAR'), $destination);
        $script = <<<'PHP'
            $phar = new Phar($argv[1]);
            $config = file_get_contents('phar://' . $argv[1] . '/config/app.php');
            $patched = preg_replace("/'version' => '[^']*'/", "'version' => '" . $argv[2] . "'", $config, 1, $count);
            if ($count !== 1) { fwrite(STDERR, "no version in config/app.php\n"); exit(1); }
            $phar['config/app.php'] = $patched;
            PHP;
        $result = self::exec([PHP_BINARY, '-d', 'phar.readonly=0', '-r', $script, $destination, $version]);
        if ($result->exitCode !== 0) {
            throw new RuntimeException("Cannot build the {$version} PHAR: {$result->stderr}");
        }
        chmod($destination, 0644);
        if (self::version($destination) !== $version) {
            throw new RuntimeException("The {$version} PHAR reports " . self::version($destination));
        }
    }

    private static function version(string $phar): string
    {
        $result = self::exec([PHP_BINARY, $phar, '--version']);

        return (string) preg_replace('/^ArkHive v?/', '', trim($result->stdout));
    }
}
