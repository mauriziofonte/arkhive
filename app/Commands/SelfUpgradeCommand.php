<?php

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\BaseCommand;

class SelfUpgradeCommand extends BaseCommand
{
    /** URL to the latest Arkhive PHAR build */
    const ARKHIVE_PHAR_URL = 'https://github.com/mauriziofonte/arkhive/raw/refs/heads/main/builds/arkhive.phar';

    protected $signature   = 'self-upgrade';
    protected $description = 'Upgrades the Arkhive command to the latest version.';

    public function handle(): void
    {
        if (!$this->isPhar) {
            $this->criticalError("Self-upgrade is only supported for PHAR installations.");
        }

        try {
            // Path to the currently running executable (PHAR or script)
            // Usually $this->fullExecutablePath = /usr/local/bin/arkhive or similar
            $destPath = $this->fullExecutablePath;

            // 1. Pick a temp file to download into
            $tmpFile = tempnam(sys_get_temp_dir(), 'arkhive-upgrade');
            if (!$tmpFile) {
                $this->criticalError("Failed to create a temporary file.");
            }

            // 2. Decide whether to use wget or curl
            $downloadCmd = null;
            if (binary_exists('wget')) {
                $downloadCmd = sprintf(
                    'wget -q -O %s %s',
                    escapeshellarg($tmpFile),
                    escapeshellarg(self::ARKHIVE_PHAR_URL)
                );
            } elseif (binary_exists('curl')) {
                $downloadCmd = sprintf(
                    'curl -sL %s -o %s',
                    escapeshellarg(self::ARKHIVE_PHAR_URL),
                    escapeshellarg($tmpFile)
                );
            } else {
                $this->criticalError("Neither wget nor curl found. Cannot self-upgrade.");
            }

            // 3. Run the download
            $this->info("Downloading latest Arkhive build from GitHub...");
            wrap_exec($downloadCmd, "Failed to download the latest Arkhive PHAR.");

            // 4. Basic file check (size > 0, etc.)
            if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
                $this->criticalError("Downloaded file is empty or missing. Aborting upgrade.");
            }

            // 5. Replace the current executable with the downloaded one (atomic move)
            //    If $destPath is not writable, this will fail. In that case, run again with sudo.
            $this->info("Replacing current executable on {$destPath}...");
            if (!@rename($tmpFile, $destPath)) {
                // fallback: try copying if rename fails
                if (!@copy($tmpFile, $destPath)) {
                    @unlink($tmpFile);
                    $this->criticalError("Failed to overwrite {$destPath}. Check permissions.");
                }
            }

            // 6. Make the new PHAR executable, and remove the temp file
            chmod($destPath, 0755);
            @unlink($tmpFile);

            // 7. Confirm success
            $this->info("Arkhive successfully upgraded! Run `arkhive --version` to verify.");
        } catch (\Throwable $e) {
            $this->criticalError($e->getMessage());
        }
    }
}
