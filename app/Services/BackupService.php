<?php

namespace Mfonte\Arkhive\Services;

use Illuminate\Support\Collection;
use Illuminate\Console\OutputStyle;

class BackupService
{
    /**
     * @var Collection
     */
    private $config;

    /**
     * @var OutputStyle|null
     */
    private $output;

    /**
     * @var FileEnumeratorService
     */
    private $fileEnumeratorService;

    /**
     * @var bool
     */
    private $checkDiskSpace = false;

    /**
     * @var bool
     */
    private $showProgress = false;

    /**
     * @param Collection         $config A collection with all necessary config keys.
     * @param OutputStyle|null   $output An optional Laravel console output for logging.
     */
    public function __construct(Collection $config, ?OutputStyle $output = null)
    {
        $this->config = $config;
        $this->output = $output;
        $this->fileEnumeratorService = new FileEnumeratorService($output);
    }

    /**
     * Sets the disk space check flag.
     *
     * @param bool $check
     */
    public function setDiskSpaceCheck(bool $check): void
    {
        $this->checkDiskSpace = $check;
    }

    /**
     * Sets the progress display flag.
     *
     * @param bool $show
     */
    public function setShowProgress(bool $show): void
    {
        $this->showProgress = $show;
    }

    /**
     * Ensures local directories exist, the backup file is writable,
     * and remote SSH is functional.
     *
     * @throws \RuntimeException on failure
     */
    public function preflightOrFail(): void
    {
        // cannot show progress in a non-TTY console,
        if ($this->showProgress && !stream_isatty(STDOUT)) {
            throw new \RuntimeException("Cannot show progress in non-tty console");
        }

        $this->checkBinaries();
        $this->checkLocalDir();
        $this->testSshConnection();

        if ($this->checkDiskSpace) {
            $this->writeln(" 💻 Checking disk space before running the backup...");
            $this->checkDiskSpace();
        }
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
        $start      = time();
        $today      = date("Y-m-d");

        // Remove older backups from remote
        $this->cleanupRemote();

        // Create DB dumps if needed
        if ($this->config->get('WITH_MYSQL')) {
            $this->dumpMysql($today);
        }
        if ($this->config->get('WITH_PGSQL')) {
            $this->dumpPostgres($today);
        }

        // Create a date-based dir on remote
        $this->mkdirRemote($today);

        // Stream backup archive (without storing it locally) to remote
        $remoteFileSize = $this->streamBackupToRemote($today);

        // Fix perms on remote
        $this->fixRemotePerms($today);

        // Cleanup local: remove final tar + any DB dumps
        $this->cleanupLocalPostBackup($today);

        $elapsed = time() - $start;
        $this->writeln(" ✅ Backup completed in $elapsed seconds.");

        return $remoteFileSize;
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
        // Try to detect the compression type by checking which file exists on remote
        $remoteBase = sprintf(
            '%s/%s/%s-%s-arkhive',
            $this->config->get('SSH_BACKUP_HOME'),
            $date,
            $date,
            $this->config->get('SSH_USER')
        );

        $withCrypt = $this->config->get('WITH_CRYPT');
        $cryptSuffix = $withCrypt ? '.enc' : '';
        
        // Try to find which compression was used by checking file existence
        $possibleFiles = [
            $remoteBase . $cryptSuffix . '.arbk',      // gzip (default)
            $remoteBase . $cryptSuffix . '.arbk.xz',   // xz
            $remoteBase . $cryptSuffix . '.tar',       // none
        ];

        $remoteFile = null;
        $detectedCompression = 'gzip';
        
        foreach ($possibleFiles as $idx => $file) {
            $checkCmd = sprintf(
                'test -f %s && echo "exists"',
                escapeshellarg($file)
            );
            $result = trim(remote_ssh_exec(
                $this->config->get('SSH_HOST'),
                $this->config->get('SSH_USER'),
                $this->config->get('SSH_PORT'),
                $checkCmd
            ));
            
            if ($result === 'exists') {
                $remoteFile = $file;
                $detectedCompression = ['gzip', 'xz', 'none'][$idx];
                break;
            }
        }

        if (!$remoteFile) {
            throw new \RuntimeException("Cannot find backup file for date {$date}. Tried: " . implode(', ', $possibleFiles));
        }

        $this->writeln(" 💻 Detected backup compression type: {$detectedCompression}");
        
        // temporary local file for download
        $tempLocal = sys_get_temp_dir() . "/arkhive-restore-{$date}-" . uniqid() . ".tmp";

        // scp download (or use your remote_ssh_exec approach + cat > local)
        $this->writeln(" 💻 Retrieving remote backup file via scp...");
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

        // Build decompression flag for tar
        $tarFlag = match($detectedCompression) {
            'gzip' => 'z',
            'xz' => 'J',
            'none' => '',
            default => 'z'
        };

        // Decrypt or just extract
        if ($this->config->get('WITH_CRYPT')) {
            $this->writeln(" 💻 Decrypting/Extracting backup (compression: {$detectedCompression})...");
            wrap_exec(
                sprintf(
                    'openssl enc -d -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass pass:%s -in %s | tar -x%sf - -C %s',
                    escapeshellarg($this->config->get('CRYPT_PASSWORD')),
                    escapeshellarg($tempLocal),
                    $tarFlag,
                    escapeshellarg($destinationLocalPath)
                ),
                "Cannot decrypt/extract backup"
            );
        } else {
            $this->writeln(" 💻 Extracting backup (compression: {$detectedCompression})...");
            wrap_exec(
                sprintf(
                    'tar -x%sf %s -C %s',
                    $tarFlag,
                    escapeshellarg($tempLocal),
                    escapeshellarg($destinationLocalPath)
                ),
                "Cannot extract backup"
            );
        }

        unlink($tempLocal);
        $this->writeln(" ✅ Restore completed to $destinationLocalPath");
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
        $required = ['du', 'df', 'ls', 'mkdir', 'awk', 'tar', 'gzip', 'ssh'];
        foreach ($required as $cmd) {
            if (!binary_exists($cmd)) {
                throw new \RuntimeException("Missing required binary: $cmd");
            }
        }

        if ($this->showProgress && !binary_exists('pv')) {
            throw new \RuntimeException("Cannot find pv binary. If showing progress, install pv or disable it.");
        }

        if ($this->config->get('WITH_CRYPT') && !binary_exists('openssl')) {
            throw new \RuntimeException("Cannot find openssl binary for encryption. Install it or disable encryption.");
        }

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
    }

    /**
     * Checks if the local backup directory exists and is writable,
     * and tests creating a temporary file inside it.
     *
     * @throws \RuntimeException on failure
     */
    private function checkLocalDir(): void
    {
        $dir      = $this->config->get('BACKUP_DIRECTORY');
        $testFile = $this->config->get('BACKUP_DIRECTORY') . '/' . uniqid('arkhive-backup-', true) . '.tmp';

        if (!is_dir($dir)) {
            throw new \RuntimeException("Backup directory does not exist: {$dir}");
        }
        if (!touch($testFile)) {
            throw new \RuntimeException("Cannot write test file to backup directory: {$dir}");
        }

        // Clean up that test file
        unlink($testFile);
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
            } else {
                $this->writeln(" 🔐 SSH connection test succeeded.");
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("SSH remote check failed: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}");
        }
    }

    /**
     * Estimate the size needed to backup Mysql/Mariadb and/or Postgres.
     *
     * @throws \RuntimeException on insufficient disk space
     */
    private function checkLocalDiskSpace(): void
    {
        $mysqlSize = $this->estimateMysqlDatabaseSize(
            explode(' ', $this->config->get('MYSQL_DATABASES')),
            $this->config->get('MYSQL_HOST'),
            $this->config->get('MYSQL_PORT'),
            $this->config->get('MYSQL_USER'),
            $this->config->get('MYSQL_PASSWORD')
        );

        $pgsqlSize = $this->estimatePostgresDatabaseSize(
            $this->config->get('PGSQL_DATABASES'),
            $this->config->get('PGSQL_HOST'),
            $this->config->get('PGSQL_USER')
        );

        $backupSize = $mysqlSize + $pgsqlSize;

        // Rudimentary estimation of reduction/overhead with compression and encryption
        $withCrypt = $this->config->get('WITH_CRYPT');
        $estimatedNeeded = $withCrypt
            ? ($backupSize * 0.8)  // 20% smaller with gzip, then encryption overhead
            : ($backupSize * 0.6); // 40% smaller with gzip

        // Check free space
        $freeBytes = disk_free_space($this->config->get('BACKUP_DIRECTORY'));

        if ($freeBytes < $estimatedNeeded) {
            $msg = sprintf(
                "Not enough free space on this machine: %s available, need ~%s",
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
        $backupDirSize = wrap_exec(
            sprintf('du -sb %s', escapeshellarg($backupDir)),
            "Cannot estimate size of backup directory"
        );
        $backupDirSize = (int) multi_explode([' ', "\t"], $backupDirSize)[0];

        $mysqlSize = $this->estimateMysqlDatabaseSize(
            explode(' ', $this->config->get('MYSQL_DATABASES')),
            $this->config->get('MYSQL_HOST'),
            $this->config->get('MYSQL_PORT'),
            $this->config->get('MYSQL_USER'),
            $this->config->get('MYSQL_PASSWORD')
        );

        $pgsqlSize = $this->estimatePostgresDatabaseSize(
            $this->config->get('PGSQL_DATABASES'),
            $this->config->get('PGSQL_HOST'),
            $this->config->get('PGSQL_USER')
        );

        $backupSize = $mysqlSize + $pgsqlSize + $backupDirSize;

        // Rudimentary estimation of reduction/overhead with compression and encryption
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
     * Creates a Mysqldump to {backup_directory}/{yyyy-mm-dd}-mysqldump.sql
     *
     * @param string $today e.g. "YYYY-MM-DD"
     * @throws \RuntimeException on failure
     */
    private function dumpMysql(string $today): void
    {
        $binary = $this->findMysqlBinaryOrFail();
        $host   = $this->config->get('MYSQL_HOST');
        $port   = $this->config->get('MYSQL_PORT');
        $user   = $this->config->get('MYSQL_USER');
        $pass   = $this->config->get('MYSQL_PASSWORD');
        $dbList = explode(' ', $this->config->get('MYSQL_DATABASES'));
        $dest   = "{$this->config->get('BACKUP_DIRECTORY')}/{$today}-mysqldump.sql";

        // Estimate MySQL DB size
        $approxBytes = $this->estimateMysqlDatabaseSize($dbList, $host, $port, $user, $pass);

        $this->writeln(" 💻 Creating MySQL dump -> $dest ...");
        $this->mysqlDump(
            $binary,
            $host,
            $port,
            $user,
            $pass,
            $dbList,
            $dest,
            max($approxBytes, 1) // avoid pv -s 0
        );
    }

    /**
     * Creates a Postgres dump to {backup_directory}/{yyyy-mm-dd}-pgsqldump.sql
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

        $this->writeln(" 💻 Creating PostgreSQL dump -> $dest ...");
        $this->pgDump(
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
        $this->writeln(" 💻 Retrieving list of remote backup directories...");

        $sshHome = $this->config->get('SSH_BACKUP_HOME');
        $listing = remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            "[ -d '{$sshHome}' ] && ls -1 '{$sshHome}' || true"
        );

        $lines = array_filter(explode("\n", $listing));
        $days = max(0, (int) $this->config->get('BACKUP_RETENTION_DAYS', 30));
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
                $timeDir = strtotime($line);
                if ($timeDir < strtotime("-{$days} days")) {
                    $this->writeln(" 💻 Removing old backup dir: {$line}");
                    remote_ssh_exec(
                        $this->config->get('SSH_HOST'),
                        $this->config->get('SSH_USER'),
                        $this->config->get('SSH_PORT'),
                        "rm -rf '{$sshHome}/{$line}'"
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
     * Streams a backup archive to a remote server, with optional encryption.
     *
     * This method creates a backup archive from the specified backup directory
     * and streams it to a remote server using SSH. The backup can be encrypted
     * using AES-256-CBC encryption if configured. Progress can also be displayed
     * during the streaming process.
     *
     * @param string $today The current date string used for naming the backup archive.
     *
     * @throws RuntimeException If any command execution fails during the process.
     */
    private function streamBackupToRemote(string $today): int
    {
        $backupDir = $this->config->get('BACKUP_DIRECTORY');
        $exclusionPatterns = $this->config->get('EXCLUSION_PATTERNS');
        $remoteDir = $this->config->get('SSH_BACKUP_HOME');
        $withCrypt = $this->config->get('WITH_CRYPT');
        $password = $this->config->get('CRYPT_PASSWORD');
        $compressionType = $this->config->get('COMPRESSION_TYPE', 'gzip');

        // Determine file extension based on compression
        $extension = match($compressionType) {
            'gzip' => '.arbk',
            'xz' => '.arbk.xz',
            'none' => '.tar',
            default => '.arbk'
        };

        // Construct the remote file name
        if ($withCrypt) {
            $remoteFile = "{$remoteDir}/{$today}/{$today}-{$this->config->get('SSH_USER')}-arkhive.enc{$extension}";
        } else {
            $remoteFile = "{$remoteDir}/{$today}/{$today}-{$this->config->get('SSH_USER')}-arkhive{$extension}";
        }

        // Get the backup directory size for progress reporting
        $sizeCmd = sprintf('du -sb %s | awk \'{print $1}\'', escapeshellarg($backupDir));
        $sizeStr = trim(wrap_exec($sizeCmd, "Cannot get backup directory size"));
        $backupDirSize = (int)$sizeStr;

        // enumerate the files inside the backup directory
        [$filesList, $excludedBytes] = $this->fileEnumeratorService->enumerateDirectory($backupDir, $exclusionPatterns);

        // adjust the backup size to account for excluded files
        $backupDirSize -= $excludedBytes;

        // the tar command is shared among all cases
        $tarCmd = sprintf('tar -cf - -T %s', escapeshellarg($filesList));

        // Build compression command
        $compressionCmd = match($compressionType) {
            'gzip' => 'gzip',
            'xz' => 'xz -9',
            'none' => 'cat',
            default => 'gzip'
        };

        if ($this->showProgress) {
            if ($withCrypt) {
                $this->writeln(" 💻 Creating {$today} Encrypted Backup Archive (compression: {$compressionType}) and streaming to remote...");
                
                $command = sprintf(
                    '%s | pv -f -s %d | %s | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass pass:%s | ssh -p %s %s@%s "cat > %s"',
                    $tarCmd,
                    $backupDirSize,
                    $compressionCmd,
                    escapeshellarg($password),
                    escapeshellarg($this->config->get('SSH_PORT')),
                    escapeshellarg($this->config->get('SSH_USER')),
                    escapeshellarg($this->config->get('SSH_HOST')),
                    escapeshellarg($remoteFile)
                );
            } else {
                $this->writeln(" 💻 Creating {$today} Non-Encrypted Backup Archive (compression: {$compressionType}) and streaming to remote...");

                $command = sprintf(
                    '%s | pv -f -s %d | %s | ssh -p %s %s@%s "cat > %s"',
                    $tarCmd,
                    $backupDirSize,
                    $compressionCmd,
                    escapeshellarg($this->config->get('SSH_PORT')),
                    escapeshellarg($this->config->get('SSH_USER')),
                    escapeshellarg($this->config->get('SSH_HOST')),
                    escapeshellarg($remoteFile)
                );
            }

            // execute the command and show progress
            $this->runPipeCommand($command, function ($percent, $etaSec, $elapsedTimeSec, $speed, $transferredSize) {
                $this->write(sprintf(
                    "\033[2K\r 🕑 Streaming: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                    $percent,
                    $elapsedTimeSec,
                    $etaSec,
                    $speed,
                    $transferredSize
                ));
            });
            $this->writeln('');
        } else {
            if ($withCrypt) {
                $this->writeln(" 💻 Creating {$today} Encrypted Backup Archive (compression: {$compressionType}) and streaming to remote...");

                $command = sprintf(
                    '%s | %s | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass pass:%s | ssh -p %s %s@%s "cat > %s"',
                    $tarCmd,
                    $compressionCmd,
                    escapeshellarg($password),
                    escapeshellarg($this->config->get('SSH_PORT')),
                    escapeshellarg($this->config->get('SSH_USER')),
                    escapeshellarg($this->config->get('SSH_HOST')),
                    escapeshellarg($remoteFile)
                );
            } else {
                $this->writeln(" 💻 Creating {$today} Non-Encrypted Backup Archive (compression: {$compressionType}) and streaming to remote...");

                $command = sprintf(
                    '%s | %s | ssh -p %s %s@%s "cat > %s"',
                    $tarCmd,
                    $compressionCmd,
                    escapeshellarg($this->config->get('SSH_PORT')),
                    escapeshellarg($this->config->get('SSH_USER')),
                    escapeshellarg($this->config->get('SSH_HOST')),
                    escapeshellarg($remoteFile)
                );
            }

            // execute the command without progress
            wrap_exec($command, "Cannot stream backup to remote");
        }

        // Check if the remote file exists and is non-empty, and fetch its size
        $remoteFileSizeStr = trim(remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            sprintf('stat -c %%s %s 2>/dev/null || echo 0', escapeshellarg($remoteFile))
        ));

        $remoteFileSize = (int) $remoteFileSizeStr;

        if ($remoteFileSize <= 0) {
            throw new \RuntimeException("Remote file verification failed: $remoteFile is missing or empty.");
        }
        
        $this->writeln(" ✅ Remote backup streamed successfully to {$remoteFile} with size: " . human_filesize($remoteFileSize));

        // Return the size of the final backup file
        return $remoteFileSize;
    }

    /**
     * Fixes permissions on remote after uploading.
     *
     * @param string $today
     */
    private function fixRemotePerms(string $today): void
    {
        $this->writeln(" 💻 Fixing permissions on remote SSH server...");
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
     * @param string $port
     * @param string $user
     * @param string $pass
     * @return int in bytes
     */
    private function estimateMysqlDatabaseSize(array $databases, string $host, string $port, string $user, string $pass): int
    {
        if (!$this->config->get('WITH_MYSQL')) {
            return 0;
        }

        // determine the binary: either mysql or mariadb
        $binary = 'mysql';
        if (binary_exists('mariadb')) {
            $binary = 'mariadb';
        } elseif (!binary_exists('mysql')) {
            throw new \RuntimeException("Cannot find MySQL/MariaDB client binary");
        }
        
        if (in_array('*', $databases, true)) {
            $sql = "SELECT SUM(data_length+index_length) FROM information_schema.tables";
        } else {
            $sql = "SELECT SUM(data_length+index_length) 
                FROM information_schema.tables 
                WHERE table_schema IN ('" . implode("','", $databases) . "')";
        }

        $cmd = sprintf(
            "%s --skip-column-names --disable-column-names -u %s -p%s --host=%s --port=%s -e %s 2>/dev/null | awk '/^[0-9]+$/ {print $1}'",
            $binary,
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($host),
            escapeshellarg($port),
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
        if (!$this->config->get('WITH_PGSQL')) {
            return 0;
        }

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
     * Creates a MySQL dump, optionally showing progress.
     *
     * @param string $dumpBinary e.g. "mysqldump" or "mariadb-dump"
     * @param string $host
     * @param string $port
     * @param string $user
     * @param string $pass
     * @param array  $dbList
     * @param string $dumpDest
     * @param int    $approxBytes
     */
    private function mysqlDump(
        string $dumpBinary,
        string $host,
        string $port,
        string $user,
        string $pass,
        array  $dbList,
        string $dumpDest,
        int    $approxBytes
    ): void {
        if (in_array('*', $dbList, true)) {
            $dbsString = '--all-databases';
        } else {
            $dbsString = implode(' ', array_map('escapeshellarg', $dbList));
            $dbsString = "--databases $dbsString";
        }

        if ($this->showProgress) {
            $command = sprintf(
                '%s -u %s -p%s --host=%s --port=%s --quick --opt --skip-lock-tables --routines --triggers %s | pv -f -s %d > %s',
                $dumpBinary,
                escapeshellarg($user),
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($port),
                $dbsString,
                max(1, $approxBytes), // avoids "pv -s 0"
                escapeshellarg($dumpDest)
            );
            
            // execute the command and show progress
            $this->runPipeCommand($command, function ($percent, $etaSec, $elapsedTimeSec, $speed, $transferredSize) {
                $this->write(sprintf(
                    "\033[2K\r 🕑 Mysql Dump: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                    $percent,
                    $elapsedTimeSec,
                    $etaSec,
                    $speed,
                    $transferredSize
                ));
            });
            $this->writeln('');
        } else {
            $command = sprintf(
                '%s -u %s -p%s --host=%s --port=%s --quick --opt --skip-lock-tables --routines --triggers %s > %s',
                $dumpBinary,
                escapeshellarg($user),
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($port),
                $dbsString,
                escapeshellarg($dumpDest)
            );
            
            // execute the command without progress
            wrap_exec($command, "Cannot create MySQL dump");
        }
    }

    /**
     * Creates a PostgreSQL dump using pg_dump, optionally showing progress.
     *
     * @param string $host
     * @param string $user
     * @param string $dbName
     * @param string $dumpDest
     * @param int    $approxBytes
     */
    private function pgDump(
        string $host,
        string $user,
        string $dbName,
        string $dumpDest,
        int    $approxBytes
    ): void {
        if ($this->showProgress) {
            $command = sprintf(
                'pg_dump -h %s -U %s -d %s --no-owner --no-privileges --format=custom | pv -f -s %d > %s',
                escapeshellarg($host),
                escapeshellarg($user),
                escapeshellarg($dbName),
                max(1, $approxBytes), // avoids "pv -s 0"
                escapeshellarg($dumpDest)
            );
            
            // execute the command and show progress
            $this->runPipeCommand($command, function ($pct, $transferred, $elapsed, $speed, $eta) {
                $this->write(sprintf(
                    "\033[2K\r 🕑 PG Dump: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                    $pct,
                    $transferred,
                    $elapsed,
                    $speed,
                    $eta
                ));
            });
            $this->writeln('');
        } else {
            $command = sprintf(
                'pg_dump -h %s -U %s -d %s --no-owner --no-privileges --format=custom > %s',
                escapeshellarg($host),
                escapeshellarg($user),
                escapeshellarg($dbName),
                escapeshellarg($dumpDest)
            );
            
            // execute the command without progress
            wrap_exec($command, "Cannot create PostgreSQL dump");
        }
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

    /**
     * Writes a message to the output, if output is available.
     *
     * @param string $message
     */
    private function writeln(string $message): void
    {
        if ($this->output) {
            $this->output->writeln($message);
        }
    }

    /**
     * Writes a message to the output without a newline, if output is available.
     *
     * @param string $message
     */
    private function write(string $message): void
    {
        if ($this->output) {
            $this->output->write($message);
        }
    }
}
