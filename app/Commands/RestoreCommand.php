<?php

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\BaseCommand;
use Mfonte\Arkhive\Services\BackupService;

/**
 * Class RestoreCommand
 *
 * Interactively lists available remote backup dates, then restores
 * the chosen backup to a user-specified local path.
 */
class RestoreCommand extends BaseCommand
{
    /**
     * We make the date an interactive choice, so we only require
     * the destination as an optional argument. If omitted, user
     * is prompted for it.
     */
    protected $signature = 'restore {destination?}';
    protected $description = 'Restores a backup from the remote server by choosing a date interactively.';

    public function handle(): void
    {
        try {
            $this->info("🚀 Welcome to Arkhive " . self::ARKHIVE_VERSION);
            $this->info("💡 Working in RESTORE mode...");

            // 1) List the remote directories in SSH_BACKUP_HOME.
            $remoteListing = remote_ssh_exec(
                $this->config->get('SSH_HOST'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_PORT'),
                "ls -1 {$this->config->get('SSH_BACKUP_HOME')}"
            );

            // 2) Filter them to valid YYYY-MM-DD directories:
            $lines = array_filter(explode("\n", $remoteListing));
            $dates = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
                    $dates[] = $line;
                }
            }

            if (empty($dates)) {
                $this->criticalError("No dated backups found on remote host.");
            }

            // 3) Present them in a choice prompt:
            $date = $this->choice("Select a date to restore", $dates);

            // 4) Get or prompt for the local destination path:
            $destination = $this->argument('destination');
            if (!$destination) {
                $destination = $this->ask("Please specify a local destination directory to restore into");
            }

            // 4b) transform the destination path to an absolute path:
            $destination = realpath($destination);

            // 5) Ensure the destination directory exists:
            if (!is_dir($destination)) {
                if (!mkdir($destination, 0755, true)) {
                    $this->criticalError("Cannot create local destination directory: {$destination}");
                }
            }

            $this->info(" 💻 Restoring backup from date: {$date} into: {$destination}");

            // 6) Call the service to handle the actual restore logic:
            $service = new BackupService($this->config, $this->output);
            $service->doRestore($date, $destination);

            $this->info(" ✅ Restore completed successfully!");
            $this->sendEmailNotification(
                "Restore Completed",
                "Restore of {$date} backup completed successfully into {$destination}."
            );
        } catch (\Throwable $e) {
            $this->criticalError($e->getMessage());
        }
    }
}
