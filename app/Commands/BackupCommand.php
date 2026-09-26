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
        // we need to load the config for this command to work
        $this->initConfig();
        
        // hydrate the options
        $this->checkAvailableDiskSpace = $this->option('with-disk-space-check');
        $this->showProgress = $this->option('with-progress');

        // anti-concurrency lock: one backup at a time per destination
        $lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'arkhive-backup-' .
            md5($this->config->get('SSH_HOST') . '|' . $this->config->get('SSH_USER') . '|' . $this->config->get('SSH_BACKUP_HOME')) . '.lock';
        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->criticalError("Another ArkHive backup to the same destination is already running (lock: {$lockFile})");
            return;
        }

        try {
            $this->info("🚀 Welcome to ArkHive " . self::$ARKHIVE_VERSION);
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
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
