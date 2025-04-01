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
    protected $signature   = 'backup 
        {--with-disk-space-check : Checks available disk space before running the backup.}
        {--with-progress : Shows progress of the backup (Incompatible with non-tty console, like cron jobs).}
    ';
    protected $description = 'Runs the Backup as per the config file';

    protected $checkAvailableDiskSpace = false;
    protected $showProgress = false;

    public function handle(): void
    {
        // hydrate the options
        $this->checkAvailableDiskSpace = $this->option('with-disk-space-check');
        $this->showProgress = $this->option('with-progress');

        try {
            $this->info("🚀 Welcome to Arkhive " . self::ARKHIVE_VERSION);
            $this->info("💡 Working in BACKUP mode...");

            $service = new BackupService($this->config, $this->output);
            $service->setDiskSpaceCheck($this->checkAvailableDiskSpace);
            $service->setShowProgress($this->showProgress);

            // run preflight checks
            $service->preflightOrFail();

            // Run the backup
            $size = $service->doBackup();

            $this->info(" ✅ Backup done!");
            $humanSize = human_filesize($size);
            $this->sendEmailNotification(
                true,
                "Backup Completed",
                "Backup of {$this->config->get('BACKUP_DIRECTORY')} completed successfully. Size: {$humanSize}"
            );
        } catch (\Throwable $e) {
            $this->criticalError("{$e->getMessage()} at {$e->getFile()}:{$e->getLine()}");
        }
    }
}
