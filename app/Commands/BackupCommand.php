<?php

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\BaseCommand;
use Mfonte\Arkhive\Services\BackupService;

/**
 * Class BackupCommand
 *
 * Runs the Backup process as per the config file.
 */
class BackupCommand extends BaseCommand
{
    protected $signature   = 'backup {--no-disk-space-check : Skip the disk space check before running the backup.}';
    protected $description = 'Runs the Backup as per the config file';

    public function handle(): void
    {
        try {
            $this->info("🚀 Welcome to Arkhive " . self::ARKHIVE_VERSION);
            $this->info("💡 Working in BACKUP mode...");

            $service = new BackupService($this->config, $this->output);
            $service->preflightOrFail();
            if (!$this->option('no-disk-space-check')) {
                $this->line(" 💻 Checking disk space before running the backup...");
                $service->checkDiskSpace();
            }

            // Run the backup
            $size = $service->doBackup();

            $this->info(" ✅ Backup done!");
            $humanSize = human_filesize($size);
            $this->sendEmailNotification(
                "Backup Completed",
                "Backup of {$this->config->get('BACKUP_DIRECTORY')} completed successfully. Size: {$humanSize}"
            );
        } catch (\Throwable $e) {
            $this->criticalError($e->getMessage());
        }
    }
}
