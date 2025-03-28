<?php

namespace Mfonte\Arkhive\Services;

use Illuminate\Support\Collection;
use Illuminate\Console\OutputStyle;

class BackupService
{
    /**
     * @var Collection
     */
    private Collection $config;

    /**
     * @var OutputStyle|null
     */
    private ?OutputStyle $output;

    /**
     * @param Collection         $config A collection with all necessary config keys.
     * @param OutputStyle|null   $output An optional Laravel console output for logging.
     */
    public function __construct(Collection $config, ?OutputStyle $output = null)
    {
        $this->config = $config;
        $this->output = $output;
    }

    /**
     * Ensures local directories exist, the backup file is writable,
     * and remote SSH is functional.
     *
     * @throws \RuntimeException on failure
     */
    public function preflightOrFail(): void
    {
        $this->checkBinaries();
        $this->checkLocalDir();
        $this->testSshConnection();
    }

    /**
     * Checks local & remote free disk space, ensuring enough for the backup.
     *
     * @throws \RuntimeException on insufficient space
     */
    public function checkDiskSpace(): void
    {
        $this->checkLocalDiskSpace();
        $this->checkRemoteDiskSpace();
    }

    /**
     * Performs the entire backup process:
     *  - MySQL / PostgreSQL dumps
     *  - Tar + (optional) encryption
     *  - Upload to remote
     *  - Cleanup local
     *
     * @return int The final backup file size in bytes.
     * @throws \RuntimeException on failure
     */
    public function doBackup(): int
    {
        $start     = time();
        $today     = date("Y-m-d");
        $backupDir = $this->config->get('BACKUP_DIRECTORY');
        $backupFile= $this->config->get('BACKUP_FILE');

        // Remove old local backup file if it exists
        $this->cleanupLocalOldFiles($backupFile);

        // Create DB dumps if needed
        if ($this->config->get('WITH_MYSQL')) {
            $this->dumpMysql($today);
        }
        if ($this->config->get('WITH_PGSQL')) {
            $this->dumpPostgres($today);
        }

        // Remove older backups from remote
        $this->cleanupRemote();

        // Create a date-based dir on remote
        $this->mkdirRemote($today);

        // Create local archive
        $this->createLocalArchive($today);

        // Upload using pv
        $finalSize = filesize($backupFile);
        $finalSizeHuman = human_filesize($finalSize);
        $this->output->writeln(" 💻 Uploading {$finalSizeHuman} bytes to remote SSH server...");
        $this->sendFileViaPv(
            $backupFile,
            $finalSize,
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "{$this->config->get('SSH_BACKUP_HOME')}/{$today}/{$today}-{$this->config->get('SSH_USER')}-arkhive.arbk"
        );

        // Fix perms on remote
        $this->fixRemotePerms($today);

        // Cleanup local: remove final tar + any DB dumps
        $this->cleanupLocalPostBackup($today);

        $elapsed = time() - $start;
        $this->output->writeln(" ✅ Backup completed in $elapsed seconds.");

        return $finalSize;
    }

    /**
     * Downloads a backup from the remote and restores it locally.
     * It respects encryption if WITH_CRYPT is set.
     *
     * @param string $date e.g. "2025-01-21"
     * @param string $destinationLocalPath Where to extract
     * @throws \RuntimeException on failure
     */
    public function doRestore(string $date, string $destinationLocalPath): void
    {
        // Construct remote path based on naming scheme
        $remoteFile = sprintf(
            '%s/%s/%s-%s-arkhive.arbk',
            $this->config->get('SSH_BACKUP_HOME'),
            $date,
            $date,
            $this->config->get('SSH_USER')
        );
        $tempLocal = sys_get_temp_dir() . "/arkhive-restore-{$date}-" . uniqid() . ".tmp";

        // scp download (or use your remote_ssh_exec approach + cat > local)
        $this->output->writeln(" 💻 Retrieving remote backup file via scp...");
        wrap_exec(
            sprintf(
                'scp -P %d %s@%s:%s %s',
                $this->config->get('SSH_PORT'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_HOST'),
                escapeshellarg($remoteFile),
                escapeshellarg($tempLocal)
            ),
            "Cannot retrieve remote backup file {$remoteFile}"
        );

        // Validate local file
        if (!is_readable($tempLocal) || filesize($tempLocal) === 0) {
            throw new \RuntimeException("Downloaded backup file '$tempLocal' is empty or unreadable");
        }

        // Decrypt or just extract
        if ($this->config->get('WITH_CRYPT')) {
            $this->output->writeln(" 💻 Decrypting/Extracting backup...");
            wrap_exec(
                sprintf(
                    'openssl enc -d -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass pass:%s -in %s | tar -xzf - -C %s',
                    escapeshellarg($this->config->get('CRYPT_PASSWORD')),
                    escapeshellarg($tempLocal),
                    escapeshellarg($destinationLocalPath)
                ),
                "Cannot decrypt/extract backup"
            );
        } else {
            $this->output->writeln(" 💻 Extracting backup...");
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
        $this->output->writeln(" ✅ Restore completed to $destinationLocalPath");
    }

    /* -----------------------------------------------------------------
       Private helper methods below
    ----------------------------------------------------------------- */

    /**
     * Checks if the required binaries are available.
     *
     * @throws \RuntimeException on missing binaries
     */
    private function checkBinaries(): void
    {
        if ($this->config->get('WITH_MYSQL')) {
            if (!binary_exists('mariadb-dump') && !binary_exists('mysqldump')) {
                throw new \RuntimeException("Cannot find mariadb-dump or mysqldump binary");
            }
        }
        if ($this->config->get('WITH_PGSQL')) {
            if (!binary_exists('pg_dump')) {
                throw new \RuntimeException("Cannot find pg_dump binary");
            }
        }

        if ($this->config->get('WITH_CRYPT') && !binary_exists('openssl')) {
            throw new \RuntimeException("Cannot find openssl binary for encryption. Install it or disable encryption.");
        }

        if (!binary_exists('tar')) {
            throw new \RuntimeException("Cannot find tar binary. Arkhive cannot run without it.");
        }

        if (!binary_exists('pv')) {
            throw new \RuntimeException("Cannot find pv binary. Arkhive cannot run without it.");
        }

        if (!binary_exists('gzip')) {
            throw new \RuntimeException("Cannot find gzip binary. Arkhive cannot run without it.");
        }
    }

    /**
     * Checks if the local backup directory exists and is writable,
     * and tests creating the backup file.
     *
     * @throws \RuntimeException on failure
     */
    private function checkLocalDir(): void
    {
        $dir  = $this->config->get('BACKUP_DIRECTORY');
        $file = $this->config->get('BACKUP_FILE');

        if (!is_dir($dir)) {
            throw new \RuntimeException("Backup directory does not exist: $dir");
        }
        if (!touch($file)) {
            throw new \RuntimeException("Cannot write backup file: $file");
        }
        // Clean up that test
        unlink($file);
    }

    /**
     * Quick SSH test: run `whoami`, compare with expected user,
     * create remote dir, etc.
     *
     * @throws \RuntimeException on failure
     */
    private function testSshConnection(): void
    {
        try {
            $whoami = remote_ssh_exec(
                $this->config->get('SSH_HOST'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_PORT'),
                'whoami'
            );
            if (trim($whoami) !== $this->config->get('SSH_USER')) {
                throw new \RuntimeException("SSH connected, but user mismatch");
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("SSH remote check failed: " . $e->getMessage());
        }
    }

    /**
     * Estimate the size of the backup directory, check local disk space.
     *
     * @throws \RuntimeException on insufficient disk space
     */
    private function checkLocalDiskSpace(): void
    {
        // Estimate size
        $backupDir = $this->config->get('BACKUP_DIRECTORY');
        $backupSize = wrap_exec(
            sprintf('du -sb %s', escapeshellarg($backupDir)),
            "Cannot estimate size of backup directory"
        );
        $backupSize = (int) multi_explode([' ', "\t"], $backupSize)[0];

        // Free space
        $freeBytes = disk_free_space(dirname($this->config->get('BACKUP_FILE')));

        // Rudimentary estimate factoring in compression
        $withCrypt = $this->config->get('WITH_CRYPT');
        $estimatedNeeded = $withCrypt
            ? ($backupSize * 0.8)  // 20% smaller with gzip, then encryption overhead
            : ($backupSize * 0.6); // 40% smaller with gzip

        if ($freeBytes < $estimatedNeeded) {
            $msg = sprintf(
                "Not enough free space: %s available, need ~%s",
                human_filesize($freeBytes),
                human_filesize((int)$estimatedNeeded)
            );
            throw new \RuntimeException($msg);
        }
    }

    /**
     * Check remote free disk space by parsing `df -k`.
     *
     * @throws \RuntimeException on insufficient disk space
     */
    private function checkRemoteDiskSpace(): void
    {
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

        // Estimate same approach as local
        $backupDir = $this->config->get('BACKUP_DIRECTORY');
        $backupSize = wrap_exec(
            sprintf('du -sb %s', escapeshellarg($backupDir)),
            "Cannot estimate size of backup directory"
        );
        $backupSize = (int) multi_explode([' ', "\t"], $backupSize)[0];

        $withCrypt = $this->config->get('WITH_CRYPT');
        $estimatedNeeded = $withCrypt
            ? ($backupSize * 0.8)
            : ($backupSize * 0.6);

        if ($remoteAvailableBytes < $estimatedNeeded) {
            $msg = sprintf(
                "Remote server does not have enough free space: %s available, need ~%s",
                human_filesize($remoteAvailableBytes),
                human_filesize((int)$estimatedNeeded)
            );
            throw new \RuntimeException($msg);
        }
    }

    /**
     * Removes the old local backup file if it exists.
     *
     * @param string $backupFile
     */
    private function cleanupLocalOldFiles(string $backupFile): void
    {
        if (file_exists($backupFile)) {
            $this->output->writeln(" 💻 Removing old backup file: $backupFile");
            unlink($backupFile);
        }
    }

    /**
     * Dumps MySQL with `pv` progress.
     *
     * @param string $today e.g. "YYYY-MM-DD"
     * @throws \RuntimeException on failure
     */
    private function dumpMysql(string $today): void
    {
        $binary = $this->findMysqlBinaryOrFail();
        $host   = $this->config->get('MYSQL_HOST');
        $user   = $this->config->get('MYSQL_USER');
        $pass   = $this->config->get('MYSQL_PASSWORD');
        $dbList = explode(' ', $this->config->get('MYSQL_DATABASES'));
        $dest   = "{$this->config->get('BACKUP_DIRECTORY')}/{$today}-mysqldump.sql";

        // Estimate MySQL DB size
        $approxBytes = $this->estimateMysqlDatabaseSize($dbList, $host, $user, $pass);

        $this->output->writeln(" 💻 Creating MySQL dump -> $dest ...");
        $this->dumpMysqlWithProgress(
            $binary,
            $host,
            $user,
            $pass,
            $dbList,
            $dest,
            max($approxBytes, 1) // avoid pv -s 0
        );
    }

    /**
     * Dumps PostgreSQL with `pv` progress.
     *
     * @param string $today e.g. "YYYY-MM-DD"
     * @throws \RuntimeException on failure
     */
    private function dumpPostgres(string $today): void
    {
        $host   = $this->config->get('PGSQL_HOST');
        $user   = $this->config->get('PGSQL_USER');
        $dbList = explode(' ', $this->config->get('PGSQL_DATABASES'));
        // For simplicity, let's assume only one DB in PGSQL_DATABASES
        $dbName = $dbList[0];
        $dest   = "{$this->config->get('BACKUP_DIRECTORY')}/{$today}-pgsqldump.sql";

        // Estimate Postgres size
        $approxBytes = $this->estimatePostgresDatabaseSize($dbName, $host, $user);

        $this->output->writeln(" 💻 Creating PostgreSQL dump -> $dest ...");
        $this->dumpPostgresWithProgress(
            $host,
            $user,
            $dbName,
            $dest,
            max($approxBytes, 1)
        );
    }

    /**
     * Removes older backups from the remote server, based on BACKUP_RETENTION_DAYS.
     * E.g. if retention is 30 days, remove directories older than 30 days.
     */
    private function cleanupRemote(): void
    {
        $this->output->writeln(" 💻 Retrieving list of remote backup directories...");
        $listing = remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "ls -1 " . $this->config->get('SSH_BACKUP_HOME')
        );

        $lines = array_filter(explode("\n", $listing));
        $days  = (int) $this->config->get('BACKUP_RETENTION_DAYS', 30);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
                $timeDir = strtotime($line);
                if ($timeDir < strtotime("-{$days} days")) {
                    $this->output->writeln(" 💻 Removing old backup dir: {$line}");
                    remote_ssh_exec(
                        $this->config->get('SSH_HOST'),
                        $this->config->get('SSH_USER'),
                        $this->config->get('SSH_PORT'),
                        "rm -rf " . $this->config->get('SSH_BACKUP_HOME') . "/{$line}"
                    );
                }
            }
        }
    }

    /**
     * Creates the remote date-based folder (e.g. /backups/2025-01-24).
     *
     * @param string $today
     */
    private function mkdirRemote(string $today): void
    {
        remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "mkdir -p " . $this->config->get('SSH_BACKUP_HOME') . "/$today"
        );
    }

    /**
     * Creates a local tar.gz or tar.gz.enc using pv to show progress.
     *
     * @param string $today
     * @throws \RuntimeException on failure
     */
    private function createLocalArchive(string $today): void
    {
        $backupFile = $this->config->get('BACKUP_FILE');
        $backupDir  = $this->config->get('BACKUP_DIRECTORY');
        $withCrypt  = $this->config->get('WITH_CRYPT');
        $password   = $this->config->get('CRYPT_PASSWORD');

        if ($withCrypt) {
            $this->output->writeln(" 💻 Creating {$today} Encrypted Backup Archive...");
            $command = sprintf(
                'tar --exclude=%s -cf - %s | pv -f -s $(du -sb %s | awk \'{print $1}\') | gzip | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass pass:%s > %s',
                escapeshellarg($backupDir),
                escapeshellarg($backupFile),
                escapeshellarg($backupDir),
                escapeshellarg($password),
                escapeshellarg($backupFile)
            );
        } else {
            $this->output->writeln(" 💻 Creating {$today} Non-Encrypted Backup Archive...");
            $command = sprintf(
                'tar --exclude=%s -cf - %s | pv -f -s $(du -sb %s | awk \'{print $1}\') | gzip > %s',
                escapeshellarg($backupDir),
                escapeshellarg($backupFile),
                escapeshellarg($backupDir),
                escapeshellarg($backupFile)
            );
        }

        // Use runPipeCommand to parse progress
        $this->runPipeCommand($command, function ($percent, $etaSec, $elapsedTimeSec, $speed, $transferredSize) {
            $this->output->write(
                sprintf(
                    "\033[2K\r 🕑 Archiving: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                    $percent,
                    $elapsedTimeSec,
                    $etaSec,
                    $speed,
                    $transferredSize
                )
            );
        });
        $this->output->writeln(''); // new line

        if (!is_readable($backupFile) || filesize($backupFile) === 0) {
            throw new \RuntimeException("Backup file $backupFile is empty or unreadable");
        }

        $size = human_filesize(filesize($backupFile));
        $this->output->writeln(" ✅ Backup file $backupFile created. Size: $size");
    }

    /**
     * Uploads a file to remote via SSH using pv, which displays progress to stderr.
     *
     * @param string $localFile
     * @param int    $fileSize
     * @param string $sshHost
     * @param string $sshUser
     * @param string $sshPort
     * @param string $remotePath
     * @throws \RuntimeException on failure
     */
    private function sendFileViaPv(
        string $localFile,
        int    $fileSize,
        string $sshHost,
        string $sshUser,
        string $sshPort,
        string $remotePath
    ): void {
        // Example:
        // pv -f -s 123456 /path/to/localfile | ssh -p 22 user@host "cat > /remote/path"
        $command = sprintf(
            'pv -f -s %d %s | ssh -p %s %s@%s "cat > %s"',
            $fileSize,
            escapeshellarg($localFile),
            escapeshellarg($sshPort),
            escapeshellarg($sshUser),
            escapeshellarg($sshHost),
            escapeshellarg($remotePath)
        );

        $this->runPipeCommand($command, function ($percent, $etaSec, $elapsedTimeSec, $speed, $transferredSize) {
            $this->output->write(sprintf(
                "\033[2K\r 🕑 Uploading: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                $percent,
                $elapsedTimeSec,
                $etaSec,
                $speed,
                $transferredSize
            ));
        });
        $this->output->writeln('');
    }

    /**
     * Fixes permissions on remote after uploading.
     *
     * @param string $today
     */
    private function fixRemotePerms(string $today): void
    {
        $this->output->writeln(" 💻 Fixing permissions on remote SSH server...");
        remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "cd {$this->config->get('SSH_BACKUP_HOME')} && find . -type d -exec chmod 0755 {} \\; && cd {$this->config->get('SSH_BACKUP_HOME')}/{$today}/ && find . -type f -exec chmod 0644 {} \\;"
        );
    }

    /**
     * Removes the final backup file and the DB dumps from local.
     *
     * @param string $today
     */
    private function cleanupLocalPostBackup(string $today): void
    {
        $backupFile = $this->config->get('BACKUP_FILE');
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }

        if ($this->config->get('WITH_MYSQL')) {
            $sqlPath = "{$this->config->get('BACKUP_DIRECTORY')}/{$today}-mysqldump.sql";
            if (file_exists($sqlPath)) {
                unlink($sqlPath);
            }
        }
        if ($this->config->get('WITH_PGSQL')) {
            $sqlPath = "{$this->config->get('BACKUP_DIRECTORY')}/{$today}-pgsqldump.sql";
            if (file_exists($sqlPath)) {
                unlink($sqlPath);
            }
        }
    }

    /**
     * Finds either mariadb-dump or mysqldump.
     *
     * @return string
     * @throws \RuntimeException if neither binary is found
     */
    private function findMysqlBinaryOrFail(): string
    {
        if (binary_exists('mariadb-dump')) {
            return 'mariadb-dump';
        }
        if (binary_exists('mysqldump')) {
            return 'mysqldump';
        }
        throw new \RuntimeException("Cannot find MySQL dump binary (mysqldump or mariadb-dump)");
    }

    /**
     * Estimate MySQL database sizes by summing data_length + index_length
     * from information_schema.
     *
     * @param array  $databases
     * @param string $host
     * @param string $user
     * @param string $pass
     * @return int in bytes
     */
    private function estimateMysqlDatabaseSize(array $databases, string $host, string $user, string $pass): int
    {
        if (in_array('*', $databases, true)) {
            $sql = "SELECT SUM(data_length+index_length) FROM information_schema.tables";
        } else {
            $sql = "SELECT SUM(data_length+index_length) 
                FROM information_schema.tables 
                WHERE table_schema IN ('" . implode("','", $databases) . "')";
        }

        $cmd = sprintf(
            "mysql --skip-column-names --disable-column-names -u %s -p%s -h %s -e %s 2>/dev/null | awk '/^[0-9]+$/ {print $1}'",
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($host),
            escapeshellarg($sql)
        );

        $output = trim(wrap_exec($cmd, "Cannot estimate MySQL size"));
        return (int) $output;
    }

    /**
     * Estimate Postgres database size via pg_database_size().
     *
     * @param string $dbName
     * @param string $host
     * @param string $user
     * @return int in bytes
     */
    private function estimatePostgresDatabaseSize(string $dbName, string $host, string $user): int
    {
        // Pseudocode again:
        //  psql -t -U user -h host -d dbName -c "SELECT pg_database_size('dbName')"
        $cmd = sprintf(
            'psql -t -U %s -h %s -d %s -c %s',
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($dbName),
            escapeshellarg("SELECT pg_database_size('$dbName')")
        );
        $output = wrap_exec($cmd, "Cannot estimate Postgres size");
        return (int) trim($output);
    }

    /**
     * Dumps MySQL with progress. (mysqldump | pv -f -s <size> > dump.sql)
     *
     * @param string $dumpBinary e.g. "mysqldump" or "mariadb-dump"
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param array  $dbList
     * @param string $dumpDest
     * @param int    $approxBytes
     */
    private function dumpMysqlWithProgress(
        string $dumpBinary,
        string $host,
        string $user,
        string $pass,
        array  $dbList,
        string $dumpDest,
        int    $approxBytes
    ): void {
        // Example:
        // mysqldump -u user -pPASS --host=host --quick --opt --skip-lock-tables --routines --triggers --databases db1 db2 ...
        // | pv -f -s <approxBytes> > dump.sql
        if (in_array('*', $dbList, true)) {
            $dbsString = '--all-databases';
        } else {
            $dbsString = implode(' ', array_map('escapeshellarg', $dbList));
            $dbsString = "--databases $dbsString";
        }

        $command = sprintf(
            '%s -u %s -p%s --host=%s --quick --opt --skip-lock-tables --routines --triggers %s | pv -f -s %d > %s',
            $dumpBinary,
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($host),
            $dbsString,
            $approxBytes,
            escapeshellarg($dumpDest)
        );

        $this->runPipeCommand($command, function ($percent, $etaSec, $elapsedTimeSec, $speed, $transferredSize) {
            $this->output->write(sprintf(
                "\033[2K\r 🕑 Mysql Dump: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                $percent,
                $elapsedTimeSec,
                $etaSec,
                $speed,
                $transferredSize
            ));
        });
        $this->output->writeln('');
    }

    /**
     * Dumps Postgres with progress. (pg_dump | pv -f -s <size> > dump.sql)
     *
     * @param string $host
     * @param string $user
     * @param string $dbName
     * @param string $dumpDest
     * @param int    $approxBytes
     */
    private function dumpPostgresWithProgress(
        string $host,
        string $user,
        string $dbName,
        string $dumpDest,
        int    $approxBytes
    ): void {
        // Example:
        // pg_dump -h host -U user -d dbName --no-owner --no-privileges --format=custom
        // | pv -f -s <approxBytes> > dump.sql
        $command = sprintf(
            'pg_dump -h %s -U %s -d %s --no-owner --no-privileges --format=custom | pv -f -s %d > %s',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($dbName),
            $approxBytes,
            escapeshellarg($dumpDest)
        );

        $this->runPipeCommand($command, function ($pct, $transferred, $elapsed, $speed, $eta) {
            $this->output->write(sprintf(
                "\033[2K\r 🕑 PG Dump: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                $pct,
                $transferred,
                $elapsed,
                $speed,
                $eta
            ));
        });
        $this->output->writeln('');
    }

    /**
     * A wrapper around proc_exec() to unify error handling and parse progress lines.
     *
     * @param string        $command
     * @param callable|null $progressCallback
     * @throws \RuntimeException if exit code != 0
     */
    private function runPipeCommand(string $command, ?callable $progressCallback = null): void
    {
        [$exitCode, $out, $err] = proc_exec($command, $progressCallback);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Command failed (exit $exitCode): $command\nStderr: $err");
        }
    }
}
