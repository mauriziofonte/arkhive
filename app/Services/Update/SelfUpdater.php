<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Update;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\ProtocolException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Command;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\System\Dependency;
use Mfonte\Arkhive\Support\Bytes;

/**
 * spec §9: replaces the running PHAR with a verified release, keeping the previous binary as
 * `<path>.bak-<version>` for --rollback. Nothing is changed until the new file is downloaded next
 * to the PHAR, checked against its sha256 (and provenance when gh is installed) and has printed
 * the expected version; then a single rename() puts it in place (at exit, for the running PHAR:
 * see PharSwap).
 */
final class SelfUpdater
{
    public const PHAR_ASSET = 'arkhive.phar';
    public const CHECKSUM_ASSET = 'arkhive.phar.sha256';
    public const REPOSITORY = 'mauriziofonte/arkhive';
    public const MIGRATION_URL = 'https://github.com/mauriziofonte/arkhive#migrating-from-1x';

    /** an asset without a size in the release JSON is still capped */
    private const MAX_PHAR_BYTES = 268435456;

    public function __construct(
        private readonly ReleaseSource $releases,
        private readonly HttpClient $http,
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
        private readonly string $currentVersion,
        private readonly string $pharPath,
        private readonly string $php = PHP_BINARY,
        private readonly ?PharSwap $swap = null,
    ) {
    }

    /**
     * The release an update would install: the requested one, the newest including pre-releases, or the latest stable.
     */
    public function target(?string $version, bool $pre): Release
    {
        if ($version !== null) {
            return $this->releases->tag($version);
        }

        return $pre ? $this->releases->newest() : $this->releases->latest();
    }

    public function isNewer(Release $release): bool
    {
        return Version::compare($release->version, $this->currentVersion) > 0;
    }

    public function crossesMajor(Release $release): bool
    {
        return Version::major($release->version) !== Version::major($this->currentVersion);
    }

    /**
     * Installs $release over the running PHAR.
     *
     * @throws ArkhiveException
     */
    public function install(Release $release, bool $allowMajor, Logger $log): UpdateResult
    {
        $this->assertPhar();
        if ($this->crossesMajor($release) && !$allowMajor) {
            throw new UsageException(
                sprintf('%s is a different major version than the running %s: its configuration is not compatible', $release->tag, $this->currentVersion),
                'Read the migration notes first (' . self::MIGRATION_URL . '), then run again with --allow-major',
            );
        }
        $phar = $release->asset(self::PHAR_ASSET);
        $checksumAsset = $release->asset(self::CHECKSUM_ASSET);
        $directory = dirname($this->pharPath);
        $this->assertWritable($directory);
        $size = $phar['size'] > 0 ? $phar['size'] : self::MAX_PHAR_BYTES;
        $free = @disk_free_space($directory);
        if ($free !== false && $phar['size'] > $free) {
            throw new RefusedException(sprintf('Not enough free space in %s for the new PHAR: %s needed, %s available', $directory, Bytes::format($phar['size']), Bytes::format((int) $free)));
        }

        $expected = $this->expectedChecksum($checksumAsset['url'], $release->tag);
        $download = $directory . '/.' . basename($this->pharPath) . '.download-' . bin2hex(random_bytes(4));
        $cleanupId = $this->cleanup->add('remove the partial download', static function () use ($download): void {
            @unlink($download);
        });
        $log->info("Downloading {$release->tag} ({$phar['url']})");
        $status = $this->http->download($phar['url'], $download, $size, $this->downloadHeaders());
        if ($status < 200 || $status >= 300) {
            throw new UnavailableException("Downloading {$phar['url']} failed (HTTP {$status})");
        }
        $actual = (string) hash_file('sha256', $download);
        if (!hash_equals($expected, $actual)) {
            throw new DataException("The downloaded {$release->tag} PHAR does not match its published sha256 (expected {$expected}, got {$actual}): not installed", hint: 'Try again later; if it persists, report it at https://github.com/' . self::REPOSITORY . '/issues');
        }
        $log->info('Checksum verified (sha256 ' . substr($actual, 0, 16) . '…)');
        $provenance = $this->verifyProvenance($download, $log);
        $mode = fileperms($this->pharPath) & 0777;
        if (!@chmod($download, $mode)) {
            throw new PermissionException("Cannot set the mode of {$download}");
        }
        $this->smokeTest($download, $release->version);

        $backup = $this->pharPath . '.bak-' . $this->currentVersion;
        $this->keepCopy($backup);
        $this->cleanup->forget($cleanupId);
        $this->swap()->rename($download, $this->pharPath, true);
        $this->removeOtherBackups($backup);

        return new UpdateResult($this->currentVersion, $release->version, $this->pharPath, $backup, $provenance);
    }

    /**
     * Puts the binary kept by the last update back, keeping the current one for another rollback.
     *
     * @throws ArkhiveException
     */
    public function rollback(Logger $log): UpdateResult
    {
        $this->assertPhar();
        $previous = $this->previous();
        if ($previous === null) {
            throw new NoInputException("There is no previous version next to {$this->pharPath} (no {$this->pharPath}.bak-*): nothing to roll back to", hint: 'Install a specific release with: arkhive self-update <tag>');
        }
        [$path, $version] = $previous;
        if (Version::compare($version, $this->currentVersion) === 0) {
            throw new NoInputException("The kept binary {$path} is the running version {$this->currentVersion}: nothing to roll back to");
        }
        $this->assertWritable(dirname($this->pharPath));
        $log->info("Rolling back to {$version} ({$path})");
        $this->smokeTest($path, $version);
        $backup = $this->pharPath . '.bak-' . $this->currentVersion;
        $this->keepCopy($backup);
        $this->swap()->rename($path, $this->pharPath, false);
        $this->removeOtherBackups($backup, $path);

        return new UpdateResult($this->currentVersion, $version, $this->pharPath, $backup, 'not applicable');
    }

    /**
     * The newest `<phar>.bak-<version>` file, with its version.
     *
     * @return array{string, string}|null
     */
    public function previous(): ?array
    {
        $found = null;
        foreach (glob($this->pharPath . '.bak-*') ?: [] as $path) {
            $version = Version::normalize(substr($path, strlen($this->pharPath . '.bak-')));
            if ($version === null || !is_file($path) || is_link($path)) {
                continue;
            }
            if ($found === null || (int) filemtime($path) > (int) filemtime($found[0])) {
                $found = [$path, $version];
            }
        }

        return $found;
    }

    private function swap(): PharSwap
    {
        return $this->swap ?? new PharSwap(false);
    }

    private function assertPhar(): void
    {
        if ($this->pharPath === '') {
            throw new UnavailableException('self-update works only for the PHAR installation; this ArkHive runs from source', 'Update a source checkout with git pull and composer install');
        }
    }

    private function assertWritable(string $directory): void
    {
        if (!is_writable($directory)) {
            throw new PermissionException(
                "{$directory} is not writable by this user, so {$this->pharPath} cannot be replaced: nothing was changed",
                'Run the update as the owner of ' . $this->pharPath . ' (for example with sudo)',
            );
        }
    }

    private function expectedChecksum(string $url, string $tag): string
    {
        $response = $this->http->get($url, $this->downloadHeaders());
        if (!$response->ok()) {
            throw new UnavailableException("Downloading the checksum of {$tag} failed (HTTP {$response->status})");
        }
        if (preg_match('/^\s*([0-9a-fA-F]{64})\b/', $response->body, $match) !== 1) {
            throw new ProtocolException("The checksum file of {$tag} does not start with a sha256: not installed");
        }

        return strtolower($match[1]);
    }

    /**
     * `gh attestation verify` when gh is installed: a mismatch stops the update; gh without a
     * login cannot check, which is reported like a missing gh.
     */
    private function verifyProvenance(string $file, Logger $log): string
    {
        $gh = $this->dependencies->find(Dependency::Gh);
        if ($gh === null) {
            $log->notice('Build provenance was not verified (gh is not installed); the sha256 matched');

            return 'not verified (gh not installed)';
        }
        $result = $this->runner->run((new Command([$gh, 'attestation', 'verify', $file, '--repo', self::REPOSITORY]))->withTimeout(120.0));
        if ($result->succeeded()) {
            $log->info('Build provenance verified with gh attestation verify');

            return 'verified';
        }
        if (preg_match('/gh auth login|GH_TOKEN|not logged in|authentication required|HTTP 401/i', $result->stderr) === 1) {
            $log->notice('Build provenance was not verified (gh is not logged in: set GH_TOKEN or run gh auth login); the sha256 matched');

            return 'not verified (gh not logged in)';
        }

        throw new DataException($result->failure('Verifying the build provenance') . ': not installed', hint: 'The download does not carry the attestation of ' . self::REPOSITORY . '; report it at https://github.com/' . self::REPOSITORY . '/issues');
    }

    private function smokeTest(string $file, string $version): void
    {
        $result = $this->runner->run((new Command([$this->php, $file, '--version']))->withTimeout(60.0));
        $printed = trim($result->stdout);
        if (!$result->succeeded() || preg_match('/(^|\s)v?' . preg_quote($version, '/') . '$/', $printed) !== 1) {
            throw new DataException(
                sprintf('%s --version printed "%s" instead of %s%s: not installed', basename($file), $printed, $version, $result->succeeded() ? '' : ' (exit ' . $result->exitCode . ')'),
                hint: $result->stderrTail() === '' ? null : $result->stderrTail(),
            );
        }
    }

    /**
     * The current PHAR also under $backup: a hard link (same file, no extra space), else a copy.
     */
    private function keepCopy(string $backup): void
    {
        if (file_exists($backup) || is_link($backup)) {
            @unlink($backup);
        }
        if (@link($this->pharPath, $backup)) {
            return;
        }
        if (!@copy($this->pharPath, $backup) || !@chmod($backup, fileperms($this->pharPath) & 0777)) {
            @unlink($backup);

            throw new RefusedException("Cannot keep a copy of {$this->pharPath} as {$backup}: nothing was changed");
        }
    }

    /**
     * Removes the `.bak-<version>` files except $keep (and $moving, still to be renamed at exit).
     */
    private function removeOtherBackups(string $keep, ?string $moving = null): void
    {
        foreach (glob($this->pharPath . '.bak-*') ?: [] as $path) {
            if ($path !== $keep && $path !== $moving && Version::normalize(substr($path, strlen($this->pharPath . '.bak-'))) !== null) {
                @unlink($path);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function downloadHeaders(): array
    {
        return ['User-Agent' => 'ArkHive/' . $this->currentVersion];
    }
}
