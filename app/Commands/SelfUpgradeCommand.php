<?php

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\BaseCommand;

class SelfUpgradeCommand extends BaseCommand
{
    const ARKHIVE_PHAR_URL = 'https://github.com/mauriziofonte/arkhive/raw/refs/heads/main/builds/arkhive.phar';
    const ARKHIVE_VERSION_FILE = 'https://raw.githubusercontent.com/mauriziofonte/arkhive/main/builds/version.txt';
    const ARKHIVE_CHECKSUM_FILE = 'https://raw.githubusercontent.com/mauriziofonte/arkhive/main/builds/arkhive.phar.sha256sum';

    protected $signature   = 'self-upgrade';
    protected $description = 'Upgrades the ArkHive phar binary to the latest version.';

    public function handle(): void
    {
        if (!$this->isPhar) {
            $this->criticalError("Self-upgrade is only supported for PHAR installations.");
        }

        try {
            $this->info("🚀 Welcome to ArkHive " . self::$ARKHIVE_VERSION);
            $this->info("💻 Working in SELF-UPGRADE mode...");
            
            // Path to the currently running executable (PHAR or script)
            // Usually $this->fullExecutablePath = /usr/local/bin/arkhive or similar
            $destPath = $this->fullExecutablePath;

            // download the latest version number
            $this->info("💡 Checking for the latest version...");
            $versionCmd = null;
            if (binary_exists('wget')) {
                $versionCmd = sprintf('wget -q -O - %s', escapeshellarg(self::ARKHIVE_VERSION_FILE));
            } elseif (binary_exists('curl')) {
                $versionCmd = sprintf('curl -sL %s', escapeshellarg(self::ARKHIVE_VERSION_FILE));
            } else {
                $this->criticalError("Neither wget nor curl found. Cannot self-upgrade.");
            }

            // Run the version check
            $latestVersion = trim(wrap_exec($versionCmd, "Failed to fetch the latest version number."));
            if (empty($latestVersion)) {
                $this->criticalError("Failed to fetch the latest version number.");
            }

            // Check if the current version is up to date
            if (version_compare(self::$ARKHIVE_VERSION, $latestVersion, '>=')) {
                $this->info("You are already running the latest version: " . self::$ARKHIVE_VERSION);
                return;
            }

            $this->info("💡 A new version is available: {$latestVersion}. Upgrading...");

            // Pick a temp file to download into
            $tmpFile = tempnam(sys_get_temp_dir(), 'arkhive-upgrade');
            if (!$tmpFile) {
                $this->criticalError("Failed to create a temporary file.");
            }

            // Download the latest PHAR
            $downloadCmd = null;
            if (binary_exists('wget')) {
                $downloadCmd = sprintf('wget -q -O %s %s', escapeshellarg($tmpFile), escapeshellarg(self::ARKHIVE_PHAR_URL));
            } elseif (binary_exists('curl')) {
                $downloadCmd = sprintf('curl -sL %s -o %s', escapeshellarg(self::ARKHIVE_PHAR_URL), escapeshellarg($tmpFile));
            } else {
                $this->criticalError("Neither wget nor curl found. Cannot self-upgrade.");
            }

            // Run the download
            $this->info("💻 Downloading latest ArkHive build from GitHub...");
            wrap_exec($downloadCmd, "Failed to download the latest ArkHive PHAR.");

            // Basic file check
            if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
                @unlink($tmpFile);
                $this->criticalError("Downloaded file is empty or missing. Aborting upgrade.");
            }

            // Verify the checksum
            $checksumCmd = null;
            if (binary_exists('wget')) {
                $checksumCmd = sprintf('wget -q -O - %s', escapeshellarg(self::ARKHIVE_CHECKSUM_FILE));
            } elseif (binary_exists('curl')) {
                $checksumCmd = sprintf('curl -sL %s', escapeshellarg(self::ARKHIVE_CHECKSUM_FILE));
            } else {
                $this->criticalError("Neither wget nor curl found. Cannot self-upgrade.");
            }
            
            // Run the checksum verification
            $this->info("💻 Verifying checksum...");
            $checksum = trim(wrap_exec($checksumCmd, "Failed to fetch the checksum."));
            if (empty($checksum)) {
                @unlink($tmpFile);
                $this->criticalError("Failed to fetch the checksum.");
            }

            // Extract the checksum value
            $pharChecksum = hash_file('sha256', $tmpFile);
            if ($checksum !== $pharChecksum) {
                @unlink($tmpFile);
                $this->criticalError("Checksum verification failed. Aborting upgrade.");
            }

            // Replace the current executable with the downloaded one
            $this->info("💻 Replacing current executable on {$destPath}...");
            if (!@rename($tmpFile, $destPath)) {
                // fallback: try copying if rename fails
                if (!@copy($tmpFile, $destPath)) {
                    @unlink($tmpFile);
                    $this->criticalError("Failed to overwrite {$destPath}. Check permissions.");
                }
            }

            // Make the new PHAR executable, and remove the temp file
            chmod($destPath, 0755);
            @unlink($tmpFile);

            // Confirm success
            $this->info("✅ ArkHive successfully upgraded! Run `arkhive --version` to verify.");
        } catch (\Throwable $e) {
            $this->criticalError("{$e->getMessage()} at {$e->getFile()}:{$e->getLine()}");
        }
    }
}
