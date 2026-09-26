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

        // Remove older backups from remote, only after today's upload has been verified
        $this->cleanupRemote($today);

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
            with_crypt_password_env((string) $this->config->get('CRYPT_PASSWORD'), function () use ($tempLocal, $tarFlag, $destinationLocalPath) {
                wrap_exec(
                    'bash -o pipefail -c ' . escapeshellarg(sprintf(
                        '%s -in %s | tar -x%sf - -C %s',
                        crypt_openssl_command(true),
                        escapeshellarg($tempLocal),
                        $tarFlag,
                        escapeshellarg($destinationLocalPath)
                    )),
                    "Cannot decrypt/extract backup"
                );
            });
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
        $required = ['du', 'df', 'ls', 'mkdir', 'awk', 'tar', 'gzip', 'ssh', 'bash', 'mkfifo', 'tee', 'wc', 'sha256sum'];
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

            // Expand tilde in SSH_BACKUP_HOME if present
            $backupHome = $this->config->get('SSH_BACKUP_HOME');
            if (strpos($backupHome, '~') === 0) {
                $expandedPath = remote_ssh_exec(
                    $this->config->get('SSH_HOST'),
                    $this->config->get('SSH_USER'),
                    $this->config->get('SSH_PORT'),
                    "echo {$backupHome}"
                );
                $this->config->put('SSH_BACKUP_HOME', trim($expandedPath));
                $this->writeln(" 💻 Expanded SSH_BACKUP_HOME to: " . trim($expandedPath));
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
    private function cleanupRemote(string $today): void
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
        $cutoff = strtotime("today -{$days} days");
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === $today) {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
                $timeDir = strtotime($line);
                if ($timeDir < $cutoff) {
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
     * The whole pipeline (tar | [pv] | compression | [openssl] | tee | ssh) runs inside bash:
     * the exit status of every stage is collected via PIPESTATUS, and a SHA-256 plus a byte
     * count of the exact stream sent over the wire are computed in the same pass (via FIFOs).
     * After upload, the remote file size must match the local byte count, and a
     * "<file>.sha256" sidecar is written next to the archive for later verification.
     *
     * @param string $today The current date string used for naming the backup archive.
     *
     * @throws \RuntimeException If any stage fails or the remote verification fails.
     */
    private function streamBackupToRemote(string $today): int
    {
        $backupDir = $this->config->get('BACKUP_DIRECTORY');
        $exclusionPatterns = $this->config->get('EXCLUSION_PATTERNS');
        $remoteDir = $this->config->get('SSH_BACKUP_HOME');
        $withCrypt = $this->config->get('WITH_CRYPT');
        $compressionType = $this->config->get('COMPRESSION_TYPE', 'gzip');

        // Determine file extension based on compression
        $extension = match($compressionType) {
            'gzip' => '.arbk',
            'xz' => '.arbk.xz',
            'none' => '.tar',
            default => '.arbk'
        };

        // Construct the remote file name
        $cryptSuffix = $withCrypt ? '.enc' : '';
        $remoteFile = "{$remoteDir}/{$today}/{$today}-{$this->config->get('SSH_USER')}-arkhive{$cryptSuffix}{$extension}";

        // Get the backup directory size for progress reporting
        $sizeCmd = sprintf('du -sb %s | awk \'{print $1}\'', escapeshellarg($backupDir));
        $sizeStr = trim(wrap_exec($sizeCmd, "Cannot get backup directory size"));
        $backupDirSize = (int)$sizeStr;

        // enumerate the files inside the backup directory
        $this->fileEnumeratorService->setShowProgress($this->showProgress);
        [$filesList, $excludedBytes] = $this->fileEnumeratorService->enumerateDirectory($backupDir, $exclusionPatterns);

        // adjust the backup size to account for excluded files
        $backupDirSize -= $excludedBytes;

        // Build the pipeline stages: [name, command]
        $stages = [['tar', sprintf('tar -cf - -T %s', escapeshellarg($filesList))]];
        if ($this->showProgress) {
            $stages[] = ['pv', sprintf('pv -f -s %d', $backupDirSize)];
        }
        $stages[] = ['compression', match($compressionType) {
            'gzip' => 'gzip',
            'xz' => 'xz -9',
            'none' => 'cat',
            default => 'gzip'
        }];
        if ($withCrypt) {
            $stages[] = ['openssl', crypt_openssl_command(false)];
        }

        $label = $withCrypt ? 'Encrypted' : 'Non-Encrypted';
        $this->writeln(" 💻 Creating {$today} {$label} Backup Archive (compression: {$compressionType}) and streaming to remote...");

        // upload to a temporary name, renamed only after verification: a failed run never clobbers a good archive
        $remotePartFile = $remoteFile . '.part';

        $workDir = make_private_temp_dir('arkhive-stream-');
        try {
            $sshStage = sprintf(
                'ssh -p %s %s@%s %s',
                escapeshellarg((string) $this->config->get('SSH_PORT')),
                escapeshellarg($this->config->get('SSH_USER')),
                escapeshellarg($this->config->get('SSH_HOST')),
                escapeshellarg('cat > ' . escapeshellarg($remotePartFile))
            );
            $stages[] = ['tee', sprintf('tee %s %s', escapeshellarg("{$workDir}/f_sha"), escapeshellarg("{$workDir}/f_size"))];
            $stages[] = ['ssh', $sshStage];

            $script = implode("\n", [
                'set -u',
                sprintf('W=%s', escapeshellarg($workDir)),
                'mkfifo "$W/f_sha" "$W/f_size"',
                'sha256sum < "$W/f_sha" | awk \'{print $1}\' > "$W/sha" & P_SHA=$!',
                'wc -c < "$W/f_size" > "$W/size" & P_SIZE=$!',
                implode(' | ', array_column($stages, 1)),
                'echo "${PIPESTATUS[*]}" > "$W/pipestatus"',
                'wait "$P_SHA"; echo $? > "$W/sha_rc"',
                'wait "$P_SIZE"; echo $? > "$W/size_rc"',
                'exit 0',
            ]);
            $scriptFile = "{$workDir}/pipeline.sh";
            file_put_contents($scriptFile, $script . "\n");

            $progressCallback = null;
            if ($this->showProgress) {
                $progressCallback = function ($percent, $etaSec, $elapsedTimeSec, $speed, $transferredSize) {
                    $this->write(sprintf(
                        "\033[2K\r 🕑 Streaming: %d%% done. [%ss elapsed, ETA %ss]. Running at %s. Transferred: %s",
                        $percent,
                        $elapsedTimeSec,
                        $etaSec,
                        $speed,
                        $transferredSize
                    ));
                };
            }

            with_crypt_password_env($withCrypt ? (string) $this->config->get('CRYPT_PASSWORD') : null, function () use ($scriptFile, $progressCallback) {
                [$exitCode, , $err] = proc_exec('bash ' . escapeshellarg($scriptFile), $progressCallback);
                if ($exitCode !== 0) {
                    throw new \RuntimeException("Backup pipeline runner failed (exit {$exitCode}). Stderr: {$err}");
                }
                $err = trim(preg_replace('/^tar: Removing leading .*$/m', '', $err));
                if ($err !== '') {
                    $this->writeln(' ⚠️  Pipeline stderr: ' . $err);
                }
            });
            if ($this->showProgress) {
                $this->writeln('');
            }

            // Verify the exit status of every stage
            $statuses = preg_split('/\s+/', trim((string) @file_get_contents("{$workDir}/pipestatus")));
            if (count($statuses) !== count($stages)) {
                throw new \RuntimeException("Cannot read pipeline exit statuses (got: " . implode(' ', $statuses) . ")");
            }
            foreach ($stages as $idx => [$stageName]) {
                $rc = (int) $statuses[$idx];
                if ($stageName === 'tar' && $rc === 1) {
                    // GNU tar: exit 1 means "some files differ / changed while reading", non fatal
                    $this->writeln(" ⚠️  tar reported files changed while reading (exit 1): archive is complete, but some files may be inconsistent.");
                    continue;
                }
                if ($rc !== 0) {
                    throw new \RuntimeException("Backup pipeline stage '{$stageName}' failed with exit code {$rc}. Statuses: " . implode(' ', $statuses));
                }
            }
            if (trim((string) @file_get_contents("{$workDir}/sha_rc")) !== '0' || trim((string) @file_get_contents("{$workDir}/size_rc")) !== '0') {
                throw new \RuntimeException("Local checksum/size computation of the backup stream failed");
            }

            $localSize = (int) trim((string) @file_get_contents("{$workDir}/size"));
            $localSha = trim((string) @file_get_contents("{$workDir}/sha"));
            if ($localSize <= 0 || !preg_match('/^[a-f0-9]{64}$/', $localSha)) {
                throw new \RuntimeException("Invalid local stream size/checksum (size: {$localSize}, sha256: {$localSha})");
            }
        } finally {
            remove_private_temp_dir($workDir);
        }

        // Verify the remote file size against the bytes actually streamed
        $remoteFileSize = (int) trim(remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            sprintf('stat -c %%s %s 2>/dev/null || echo 0', escapeshellarg($remotePartFile))
        ));
        if ($remoteFileSize !== $localSize) {
            throw new \RuntimeException("Remote file verification failed: {$remotePartFile} is {$remoteFileSize} bytes, expected {$localSize}.");
        }

        // Promote the verified upload to its final name
        remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            sprintf('mv -f %s %s', escapeshellarg($remotePartFile), escapeshellarg($remoteFile))
        );

        // Write the checksum sidecar (sha256sum format) next to the archive
        remote_ssh_exec(
            $this->config->get('SSH_HOST'),
            $this->config->get('SSH_USER'),
            $this->config->get('SSH_PORT'),
            sprintf('printf %%s %s > %s', escapeshellarg($localSha . '  ' . basename($remoteFile) . "\n"), escapeshellarg($remoteFile . '.sha256'))
        );

        $this->writeln(" ✅ Remote backup streamed and verified: {$remoteFile} (" . human_filesize($remoteFileSize) . ", sha256 {$localSha})");

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
            // only today's backup dir: the backup home itself keeps whatever mode the remote admin chose (e.g. 0750)
            sprintf('cd %s && chmod 0755 . && find . -type f -exec chmod 0644 {} +', escapeshellarg($this->config->get('SSH_BACKUP_HOME') . '/' . $today))
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
