<?php

namespace Mfonte\Arkhive\Services;

use Illuminate\Support\Collection;
use Illuminate\Console\OutputStyle;

/**
 * Class BackupService
 * Handles backup (and restore) logic to keep BackupCommand/RestoreCommand simpler.
 */
class BackupService
{
    /** @var Collection $config */
    private $config;
    /** @var OutputStyle|null $output */
    private $output;

    public function __construct(Collection $config, ?OutputStyle $output = null)
    {
        $this->config = $config;
        $this->output = $output;
    }

    /**
     * Validates the environment for the backup.
     * E.g., check if local directories exist, or if remote is reachable, etc.
     */
    public function preflightOrFail(): void
    {
        // Local dir exist?
        if (!is_dir($this->config->get('BACKUP_DIRECTORY'))) {
            throw new \RuntimeException("Backup directory does not exist: {$this->config->get('BACKUP_DIRECTORY')}");
        }

        // Touch test
        if (!touch($this->config->get('BACKUP_FILE'))) {
            throw new \RuntimeException("Failed to touch backup file: {$this->config->get('BACKUP_FILE')}");
        }
        unlink($this->config->get('BACKUP_FILE'));

        // Remote check
        try {
            $whoami = remote_ssh_exec(
                $this->config->get('SSH_HOST'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_PORT'),
                'whoami'
            );
            if (trim($whoami) !== $this->config->get('SSH_USER')) {
                throw new \RuntimeException("SSH connected, but user mismatch.");
            }

            remote_ssh_exec(
                $this->config->get('SSH_HOST'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_PORT'),
                "mkdir -p \"{$this->config->get('SSH_BACKUP_HOME')}\""
            );

            $remoteBackupDir = remote_ssh_exec(
                $this->config->get('SSH_HOST'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_PORT'),
                "ls -d \"{$this->config->get('SSH_BACKUP_HOME')}\""
            );
            if (trim($remoteBackupDir) !== $this->config->get('SSH_BACKUP_HOME')) {
                throw new \RuntimeException("Could not create remote backup dir. Check permissions.");
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("SSH remote check failed: " . $e->getMessage());
        }
    }

    /**
     * Validates correct disk space on both local and remote.
     */
    public function checkDiskSpace(): void
    {
        // estimate size of backup dir
        $this->output->writeln("[i] Estimating size of backup directory...");
        $backupSize = wrap_exec(
            sprintf(
                'du -sb %s',
                escapeshellarg($this->config->get('BACKUP_DIRECTORY'))
            ),
            "Cannot estimate size of backup directory"
        );
        $backupSize = multi_explode([' ', chr(9)], $backupSize)[0];

        // how much free space do we have?
        $freeBytes = disk_free_space(dirname($this->config->get('BACKUP_FILE')));

        // add the crypt overhead
        if ($this->config->get('WITH_CRYPT')) {
            // consider 20% reduction in size due to gzip compression, but with crypt overhead
            $estimatedFreeSpaceNeeded = $backupSize * 0.8;
        } else {
            // consider 40% reduction in size due to gzip compression
            $estimatedFreeSpaceNeeded = $backupSize * 0.6;
        }

        if ($freeBytes < $estimatedFreeSpaceNeeded) {
            $freeBytes = human_filesize($freeBytes);
            $estimatedFreeSpaceNeeded = human_filesize($estimatedFreeSpaceNeeded);
            throw new \RuntimeException("Not enough free space: {$freeBytes} available, but we need an estimated {$estimatedFreeSpaceNeeded}");
        }

        // estimate remote free space
        $remotePath = $this->config->get('SSH_BACKUP_HOME');
        $dfOutput = remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "df -k " . escapeshellarg($remotePath) . " | tail -1"
        );
        $parts = preg_split('/\s+/', trim($dfOutput));
        $remoteAvailableKB = (int) ($parts[3] ?? 0);
        $remoteAvailableBytes = $remoteAvailableKB * 1024;

        if ($remoteAvailableBytes < $estimatedFreeSpaceNeeded) {
            $remoteAvailableBytes = human_filesize($remoteAvailableBytes);
            $estimatedFreeSpaceNeeded = human_filesize($estimatedFreeSpaceNeeded);
            throw new \RuntimeException("Remote server does not have enough free space: {$remoteAvailableBytes} available, but we need an estimated {$estimatedFreeSpaceNeeded}");
        }
    }

    /**
     * Perform the actual backup steps:
     * - MySQL dump
     * - PGSQL dump
     * - Tar & encrypt (optional)
     * - scp to remote
     * - cleanup local
     *
     * @return int The size in bytes of the backup file
     * @throws \RuntimeException on failure
     */
    public function doBackup(): int
    {
        $today = date("Y-m-d");
        $init  = time();

        if (file_exists($this->config->get('BACKUP_FILE'))) {
            $this->output->writeln("[i] Removing old backup file: {$this->config->get('BACKUP_FILE')}");
            unlink($this->config->get('BACKUP_FILE'));
        }

        // MySQL/MariaDB Backup
        if ($this->config->get('WITH_MYSQL')) {
            $binary = $tool = null;
            if (binary_exists('mariadb-dump')) {
                $binary = 'mariadb-dump';
                $tool = 'MariaDB';
            } elseif (binary_exists('mysqldump')) {
                $binary = 'mysqldump';
                $tool = 'MySQL';
            } else {
                throw new \RuntimeException("Cannot find MySQL dump binary (mysqldump or mariadb-dump)");
            }

            $dest = "{$this->config->get('BACKUP_DIRECTORY')}/{$today}-mysqldump.sql";
            $this->output->writeln("[i] Creating {$tool} dump to {$dest}...");
            wrap_exec(
                sprintf(
                    '%s -u %s -p%s --host=%s --opt --quick --compact --skip-lock-tables --routines --triggers --databases %s > %s',
                    $binary,
                    $this->config->get('MYSQL_USER'),
                    $this->config->get('MYSQL_PASSWORD'),
                    $this->config->get('MYSQL_HOST'),
                    $this->config->get('MYSQL_DATABASES'),
                    escapeshellarg($dest)
                ),
                "Cannot create MySQL dump"
            );
        }

        // PGSQL Backup
        if ($this->config->get('WITH_PGSQL')) {
            $dest = "{$this->config->get('BACKUP_DIRECTORY')}/{$today}-pgsqldump.sql";
            $this->output->writeln("[i] Creating PostgreSQL dump to {$dest}...");
            wrap_exec(
                sprintf(
                    'pg_dump -U %s -h %s -d %s --no-owner --no-privileges --format=custom > %s',
                    $this->config->get('PGSQL_USER'),
                    $this->config->get('PGSQL_HOST'),
                    $this->config->get('PGSQL_DATABASES'),
                    escapeshellarg($dest)
                ),
                "Cannot create PostgreSQL dump"
            );
        }

        // Housekeeping old backups on remote, based on BACKUP_RETENTION_DAYS
        $this->cleanupRemote();

        // Create remote dated dir
        remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "mkdir -p {$this->config->get('SSH_BACKUP_HOME')}/{$today}"
        );

        // Create local tar (with or without encryption)
        $this->createLocalArchive($today);

        // scp to remote
        $size = human_filesize($this->config->get('BACKUP_FILE'));
        $this->output->writeln("[i] Sending {$size} of backup file to remote SSH server {$this->config->get('SSH_HOST')}...");
        wrap_exec(
            sprintf(
                'scp %s %s@%s:%s/%s/%s-%s-arkhive.bak',
                $this->config->get('BACKUP_FILE'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_HOST'),
                $this->config->get('SSH_BACKUP_HOME'),
                $today,
                $today,
                $this->config->get('SSH_USER')
            ),
            "Cannot send backup to remote"
        );

        // fix perms on remote
        $this->output->writeln("[i] Fixing permissions on remote SSH server...");
        remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "cd {$this->config->get('SSH_BACKUP_HOME')} && find . -type d -exec chmod 0755 {} \\; && cd {$this->config->get('SSH_BACKUP_HOME')}/{$today}/ && find . -type f -exec chmod 0644 {} \\;"
        );

        // Cleanup local
        $size = filesize($this->config->get('BACKUP_FILE'));
        unlink($this->config->get('BACKUP_FILE'));
        if ($this->config->get('WITH_MYSQL')) {
            unlink("{$this->config->get('BACKUP_DIRECTORY')}/{$today}-mysqldump.sql");
        }
        if ($this->config->get('WITH_PGSQL')) {
            unlink("{$this->config->get('BACKUP_DIRECTORY')}/{$today}-pgsqldump.sql");
        }

        $end = time();
        $elapsed = $end - $init;
        $this->output->writeln("[i] Backup completed in {$elapsed} seconds.");

        return $size;
    }

    /**
     * Attempts to restore from the remote backup.
     *
     * @param string $date e.g. "2025-01-21"
     * @param string $destinationLocalPath
     */
    public function doRestore(string $date, string $destinationLocalPath): void
    {
        // We'll assume a standard naming scheme: {date}-{user}-arkhive.bak
        $remoteFile = "{$this->config->get('SSH_BACKUP_HOME')}/{$date}/{$date}-{$this->config->get('SSH_USER')}-arkhive.bak";
        $tempLocal  = sys_get_temp_dir() . "/arkhive-restore-{$date}-" . uniqid() . ".tmp";

        // scp download
        $this->output->writeln("[i] Retrieving remote backup file...");
        wrap_exec(
            sprintf(
                'scp %s@%s:%s %s',
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_HOST'),
                $remoteFile,
                $tempLocal
            ),
            "Cannot retrieve remote backup file"
        );

        // Ensure $tempLocal exists and is readable, and has a filesize > 0
        if (!is_readable($tempLocal) || filesize($tempLocal) === 0) {
            throw new \RuntimeException("Downloaded backup file is empty or unreadable");
        }

        // decrypt or just extract
        if ($this->config->get('WITH_CRYPT')) {
            $this->output->writeln("[i] Decrypting/Extracting backup...");
            wrap_exec(
                sprintf(
                    'openssl enc -d -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass pass:%s -in %s | tar -xzf - -C %s',
                    $this->config->get('CRYPT_PASSWORD'),
                    escapeshellarg($tempLocal),
                    escapeshellarg($destinationLocalPath)
                ),
                "Cannot decrypt/extract backup"
            );
        } else {
            $this->output->writeln("[i] Extracting backup...");
            wrap_exec(
                sprintf(
                    'tar -xzf %s -C %s',
                    escapeshellarg($tempLocal),
                    escapeshellarg($destinationLocalPath)
                ),
                "Cannot extract backup"
            );
        }

        unlink($tempLocal);
    }

    /**
     * Removes older backups from the remote server, based on BACKUP_RETENTION_DAYS.
     */
    private function cleanupRemote(): void
    {
        $this->output->writeln("[i] Retrieving list of remote backup directories...");
        $listing = remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "ls -1 {$this->config->get('SSH_BACKUP_HOME')}"
        );

        $lines = array_filter(explode("\n", $listing));
        foreach ($lines as $line) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($line))) {
                $d = strtotime($line);
                if ($d < strtotime("-{$this->config->get('BACKUP_RETENTION_DAYS')} days")) {
                    $this->output->writeln("[i] Removing old backup dir: {$line}");
                    remote_ssh_exec(
                        $this->config->get('SSH_HOST'),
                        $this->config->get('SSH_USER'),
                        $this->config->get('SSH_PORT'),
                        "rm -rf {$this->config->get('SSH_BACKUP_HOME')}/{$line}"
                    );
                }
            }
        }
    }

    /**
     * Creates a tar.gz or tar.gz.enc locally.
     */
    private function createLocalArchive(string $today): void
    {
        if ($this->config->get('WITH_CRYPT')) {
            $this->output->writeln("[i] Creating {$today} Encrypted Backup Archive...");
            wrap_exec(
                sprintf(
                    'tar -zcf - --exclude=\'*.csv\' --exclude=\'*.iso\' --exclude=\'*.txt\' --exclude=\'*.log\' --exclude=\'*.tgz\' --exclude=\'*.sql.gz\' --exclude=\'*.tar.gz\' --exclude=%s %s | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass pass:%s > %s',
                    escapeshellarg($this->config->get('BACKUP_FILE')),
                    escapeshellarg($this->config->get('BACKUP_DIRECTORY')),
                    $this->config->get('CRYPT_PASSWORD'),
                    escapeshellarg($this->config->get('BACKUP_FILE'))
                ),
                "Cannot create Encrypted Backup Archive"
            );
        } else {
            $this->output->writeln("[i] Creating {$today} Non-Encrypted Backup Archive...");
            wrap_exec(
                sprintf(
                    'tar -zcf %s --exclude=\'*.csv\' --exclude=\'*.iso\' --exclude=\'*.txt\' --exclude=\'*.log\' --exclude=\'*.tgz\' --exclude=\'*.sql.gz\' --exclude=\'*.tar.gz\' --exclude=%s %s',
                    escapeshellarg($this->config->get('BACKUP_FILE')),
                    escapeshellarg($this->config->get('BACKUP_FILE')),
                    escapeshellarg($this->config->get('BACKUP_DIRECTORY'))
                ),
                "Cannot create Non-Encrypted Backup Archive"
            );
        }

        // ensure BACKUP_FILE exists and is readable
        if (!is_readable($this->config->get('BACKUP_FILE')) || filesize($this->config->get('BACKUP_FILE')) === 0) {
            throw new \RuntimeException("Backup file {$this->config->get('BACKUP_FILE')} is empty or unreadable");
        }

        $size = human_filesize($this->config->get('BACKUP_FILE'));
        $this->output->writeln("[i] Backup file {$this->config->get('BACKUP_FILE')} created. Size: {$size}");
    }
}
