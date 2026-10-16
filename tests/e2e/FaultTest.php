<?php

declare(strict_types=1);

namespace Tests\e2e;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\ExitCode;
use Tests\Support\CliResult;

/**
 * spec §13.6 fault injection: the SFTP server frozen before and during a backup and mid-restore, a
 * full repository disk, a clock that jumped, a dump server that is down or frozen. Each case ends with a precise error, no
 * snapshot counted as a success, no leftover temporary data and the lock released. (The corrupted
 * pack is OperationsTest::testACorruptedPackFailsTheDrillAndMaintain.)
 */
final class FaultTest extends E2eTestCase
{
    private string $dir;
    private string $tree;
    private string $repository;
    private string $sftpCommand;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->tree = "{$this->dir}/site";
        mkdir("{$this->tree}/data", 0755, true);
        file_put_contents("{$this->tree}/index.php", "<?php echo 'hello';\n");
        mkdir("{$this->dir}/locks");
        mkdir("{$this->dir}/tmp", 0700);
        [$this->repository, $this->sftpCommand] = self::sftpRepository();
    }

    public function testTheSftpServerFrozenMidBackup(): void
    {
        $this->needsPause();
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        file_put_contents("{$this->tree}/data/large.bin", random_bytes(16 * 1024 * 1024));
        $config = $this->config(['RESTIC_BINARY' => $this->throttledRestic('--limit-upload')]);

        $started = self::startArkhive(['backup', '--config', $config], $this->runEnv());
        self::assertTrue(self::waitFor($this->lockHeld(...), 60), 'the backup started');
        sleep(3);
        $result = $this->whilePaused(static fn (): CliResult => self::finish($started));

        self::assertSame(ExitCode::UNAVAILABLE, $result->exitCode, $result->output());
        self::assertMatchesRegularExpression('/error restic backup failed \(throttled-restic exit 1\): .*ssh command exited/', $result->stderr);
        self::assertStringContainsString('notice Check that the repository server is up and that this command works by hand: ' . $this->sftpCommand, $result->stderr);
        $this->assertCleanAfterFailure();
        self::assertSame([], $this->snapshots(), 'no snapshot from the interrupted upload');

        // once the server is back, the next run works: the lock restic could not remove is its own, stale
        $again = self::arkhive(['backup', '--config', $this->config()], $this->runEnv());
        self::assertSame(0, $again->exitCode, $again->output());
        self::assertCount(1, $this->snapshots());
    }

    /**
     * The same freeze with a RESTIC_SFTP_COMMAND in the README's form (port and key, no keepalives):
     * ArkHive adds ServerAliveInterval=15 and ServerAliveCountMax=4, so the run fails in about a
     * minute instead of hanging for as long as the server is frozen.
     */
    public function testTheSftpServerFrozenMidBackupWithACommandWithoutKeepalives(): void
    {
        $this->needsPause();
        $this->sftpCommand = str_replace(' -o BatchMode=yes -o ServerAliveInterval=5 -o ServerAliveCountMax=3', '', $this->sftpCommand);
        self::assertStringNotContainsString('ServerAlive', $this->sftpCommand);
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        file_put_contents("{$this->tree}/data/large.bin", random_bytes(16 * 1024 * 1024));
        $config = $this->config(['RESTIC_BINARY' => $this->throttledRestic('--limit-upload')]);

        $started = self::startArkhive(['backup', '--config', $config], $this->runEnv());
        self::assertTrue(self::waitFor($this->lockHeld(...), 60), 'the backup started');
        sleep(3);
        $begin = microtime(true);
        $result = $this->whilePaused(static function () use ($started): CliResult {
            // proc_close() cannot report an exit status proc_get_status() already collected: keep it
            $exit = null;
            $ended = self::waitFor(static function () use ($started, &$exit): bool {
                $status = proc_get_status($started[0]);
                $exit = $status['exitcode'];

                return !$status['running'];
            }, 180);
            if (!$ended) {
                proc_terminate($started[0], 9);
                self::finish($started);
                self::fail('the backup still hung 180 s after the server froze');
            }
            $output = self::finish($started);

            return new CliResult((int) $exit, $output->stdout, $output->stderr);
        });

        self::assertSame(ExitCode::UNAVAILABLE, $result->exitCode, $result->output());
        self::assertLessThan(150, microtime(true) - $begin, 'failed by the keepalives (15 s x 4), not by the end of the freeze');
        $this->assertCleanAfterFailure();
        self::assertSame([], $this->snapshots(), 'no snapshot from the interrupted upload');
    }

    /**
     * A server frozen before the run: the TCP connection opens but the SSH greeting never comes, and
     * the keepalives only start after it. ConnectTimeout=30 (added by ArkHive) fails each attempt to
     * open the repository instead of holding the run until the server is back.
     */
    public function testTheSftpServerFrozenBeforeTheBackupStarts(): void
    {
        $this->needsPause();
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        self::assertStringNotContainsString('ConnectTimeout', $this->sftpCommand);

        $begin = microtime(true);
        $result = $this->whilePaused(function (): CliResult {
            $started = self::startArkhive(['backup', '--config', $this->config()], $this->runEnv());
            $exit = null;
            $ended = self::waitFor(static function () use ($started, &$exit): bool {
                $status = proc_get_status($started[0]);
                $exit = $status['exitcode'];

                return !$status['running'];
            }, 280);
            if (!$ended) {
                proc_terminate($started[0], 9);
                self::finish($started);
                self::fail('the backup still hung 280 s after it started against a frozen server');
            }
            $output = self::finish($started);

            return new CliResult((int) $exit, $output->stdout, $output->stderr);
        });

        self::assertSame(ExitCode::UNAVAILABLE, $result->exitCode, $result->output());
        self::assertLessThan(250, microtime(true) - $begin, 'three attempts bounded by ConnectTimeout (30 s each), not by the end of the freeze');
        $this->assertCleanAfterFailure();
        self::assertSame([], $this->snapshots());
    }

    public function testTheSftpServerFrozenMidRestore(): void
    {
        $this->needsPause();
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        file_put_contents("{$this->tree}/data/large.bin", random_bytes(16 * 1024 * 1024));
        $backup = self::arkhive(['backup', '--config', $this->config()], $this->runEnv());
        self::assertSame(0, $backup->exitCode, $backup->output());
        $target = "{$this->dir}/restored";
        $config = $this->config(['RESTIC_BINARY' => $this->throttledRestic('--limit-download')]);

        $started = self::startArkhive(['restore', '--config', $config, '--snapshot', 'latest', '--target', $target, '--yes'], $this->runEnv());
        self::assertTrue(self::waitFor(static fn (): bool => is_dir($target), 120), 'the restore started writing');
        sleep(2);
        $result = $this->whilePaused(static fn (): CliResult => self::finish($started));

        self::assertSame(ExitCode::UNAVAILABLE, $result->exitCode, $result->output());
        self::assertStringContainsString('error ', $result->stderr);
        self::assertStringContainsString('notice Check that the repository server is up and that this command works by hand: ' . $this->sftpCommand, $result->stderr);
        self::assertFileDoesNotExist($target, 'the partial target is removed');
        $this->assertCleanAfterFailure();
    }

    public function testTheRepositoryDiskIsFull(): void
    {
        if (!self::root()) {
            self::markTestSkipped('mounting a small tmpfs needs root (CI runs the e2e suite as root)');
        }
        $full = "{$this->dir}/full";
        mkdir($full);
        $mount = self::exec(['mount', '-t', 'tmpfs', '-o', 'size=4m', 'tmpfs', $full]);
        self::assertSame(0, $mount->exitCode, $mount->stderr);
        try {
            $this->repository = "{$full}/repo";
            $this->sftpCommand = '';
            $this->arkhiveInit($this->dir, $this->repository, null);
            file_put_contents("{$this->tree}/data/large.bin", random_bytes(8 * 1024 * 1024));

            $result = self::arkhive(['backup', '--config', $this->config()], $this->runEnv());

            self::assertSame(ExitCode::IO_ERROR, $result->exitCode, $result->output());
            self::assertStringContainsString('no space left on device', $result->stderr);
            $this->assertCleanAfterFailure();
            self::assertSame([], $this->snapshots(), 'no snapshot on a full disk');
        } finally {
            self::exec(['umount', $full]);
        }
    }

    /**
     * MySQL down (nothing listens on port 1), PostgreSQL up.
     *
     * @return array<string, string>
     */
    private function oneDatabaseServerDown(): array
    {
        return [
            'DUMP_DIRECTORY' => "{$this->dir}/dumps",
            'WITH_MYSQL' => 'true',
            'MYSQL_HOST' => self::env('E2E_MYSQL_HOST'),
            'MYSQL_PORT' => '1',
            'MYSQL_USER' => self::env('E2E_MYSQL_USER'),
            'MYSQL_PASSWORD' => self::env('E2E_MYSQL_PASSWORD'),
            'MYSQL_DATABASES' => 'shop',
            'WITH_PGSQL' => 'true',
            'PGSQL_HOST' => self::env('E2E_PGSQL_HOST'),
            'PGSQL_PORT' => self::env('E2E_PGSQL_PORT'),
            'PGSQL_USER' => self::env('E2E_PGSQL_USER'),
            'PGSQL_PASSWORD' => self::env('E2E_PGSQL_PASSWORD'),
            'PGSQL_DATABASES' => 'inventory',
        ];
    }

    public function testOneDatabaseServerDownOfTwoIsExit3WithTheOtherDumpInTheSnapshot(): void
    {
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);

        $result = self::arkhive(['backup', '--config', $this->config($this->oneDatabaseServerDown())], $this->runEnv());

        self::assertSame(ExitCode::WARNING, $result->exitCode, $result->output());
        self::assertStringContainsString('warning The MySQL dump of shop failed, the backup goes on without it: ', $result->stderr);
        self::assertStringContainsString("Can't connect", $result->stderr);
        self::assertStringContainsString('success Dumped PostgreSQL database inventory', $result->stderr);
        self::assertCount(1, $this->snapshots());
        $listed = self::restic(['ls', 'latest', '--no-lock'], $this->repository, $this->sftpCommand);
        self::assertSame(0, $listed->exitCode, $listed->stderr);
        self::assertStringContainsString("{$this->dir}/dumps/pgsql/inventory.sql", $listed->stdout);
        self::assertStringNotContainsString("{$this->dir}/dumps/mysql/", $listed->stdout);
        self::assertStringContainsString("{$this->tree}/index.php", $listed->stdout);
        self::assertSame([], glob("{$this->dir}/dumps/*/*") ?: [], 'the dump directory is emptied');
        $this->assertCleanAfterFailure();
    }

    public function testWithDumpRequiredADatabaseServerDownIsExit69AfterTheFilesAreSaved(): void
    {
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);

        $result = self::arkhive(['backup', '--config', $this->config([...$this->oneDatabaseServerDown(), 'DUMP_REQUIRED' => 'true'])], $this->runEnv());

        self::assertSame(ExitCode::UNAVAILABLE, $result->exitCode, $result->output());
        self::assertMatchesRegularExpression('/error 1 database dump\(s\) failed and DUMP_REQUIRED is true: the files are in snapshot [0-9a-f]{8}, those databases are not/', $result->stderr);
        self::assertCount(1, $this->snapshots());
        self::assertSame([], glob("{$this->dir}/dumps/*/*") ?: []);
        $this->assertCleanAfterFailure();
    }

    /**
     * A database server frozen (paused, its storage hung): the dump connects and then receives
     * nothing. DUMP_STALL_TIMEOUT stops it, the other database of that server is not attempted, the
     * other server's dump and the files are saved, and the run ends with exit 3 in seconds instead of
     * waiting for the server to come back.
     */
    public function testAFrozenDatabaseServerFailsItsDumpsAndTheRestIsSaved(): void
    {
        if ((getenv('E2E_MYSQL_PAUSE') ?: '') === '' || (getenv('E2E_MYSQL_RESUME') ?: '') === '') {
            self::markTestSkipped('E2E_MYSQL_PAUSE / E2E_MYSQL_RESUME are not set (run.sh sets them to docker compose pause/unpause)');
        }
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        $config = $this->config([
            ...$this->oneDatabaseServerDown(),
            'MYSQL_PORT' => self::env('E2E_MYSQL_PORT'),
            'MYSQL_DATABASES' => 'shop blog',
            'DUMP_STALL_TIMEOUT' => '5',
        ]);

        $pause = self::exec(['sh', '-c', self::env('E2E_MYSQL_PAUSE')]);
        self::assertSame(0, $pause->exitCode, $pause->output());
        $begin = microtime(true);
        try {
            $result = self::arkhive(['backup', '--config', $config], $this->runEnv());
        } finally {
            $resume = self::exec(['sh', '-c', self::env('E2E_MYSQL_RESUME')]);
            self::assertSame(0, $resume->exitCode, $resume->output());
        }

        self::assertSame(ExitCode::WARNING, $result->exitCode, $result->output());
        self::assertLessThan(120, microtime(true) - $begin, 'stopped by DUMP_STALL_TIMEOUT, not by the end of the freeze');
        self::assertStringContainsString('warning The MySQL dump of shop failed, the backup goes on without it: MySQL dump of database shop made no progress for 5s and was stopped', $result->stderr);
        self::assertStringContainsString('warning The MySQL dump of blog was skipped, the backup goes on without it: Not attempted: the MySQL server did not answer the dump of shop', $result->stderr);
        self::assertStringContainsString('success Dumped PostgreSQL database inventory', $result->stderr);
        self::assertCount(1, $this->snapshots());
        $listed = self::restic(['ls', 'latest', '--no-lock'], $this->repository, $this->sftpCommand);
        self::assertSame(0, $listed->exitCode, $listed->stderr);
        self::assertStringContainsString("{$this->dir}/dumps/pgsql/inventory.sql", $listed->stdout);
        self::assertStringNotContainsString("{$this->dir}/dumps/mysql/", $listed->stdout);
        self::assertSame([], glob("{$this->dir}/dumps/*/*") ?: [], 'no partial dump left');
        $this->assertCleanAfterFailure();
    }

    public function testAClockThatJumpedBack(): void
    {
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        // a snapshot written while the clock was two days ahead, as after an NTP correction
        $ahead = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d H:i:s');
        $future = self::restic(['backup', '--host', 'e2e.example.com', '--tag', 'arkhive', '--time', $ahead, $this->tree], $this->repository, $this->sftpCommand);
        self::assertSame(0, $future->exitCode, $future->stderr);

        $backup = self::arkhive(['backup', '--config', $this->config(), '--json'], $this->runEnv());
        self::assertSame(0, $backup->exitCode, $backup->output());
        self::assertCount(2, $this->snapshots());

        $doctor = self::arkhive(['doctor', '--config', $this->config()], $this->runEnv());
        self::assertSame(ExitCode::FAILURE, $doctor->exitCode, $doctor->output());
        self::assertMatchesRegularExpression('/FAIL\s+clock\s+This clock is behind: the repository has an entry dated \S+ \S+ UTC, 4[78] h in the future/', $doctor->stdout);
        self::assertStringContainsString('hint: Synchronise the clock (timedatectl set-ntp true, or chrony/ntpd)', $doctor->stdout);

        // maintain keeps both: the future snapshot is not mistaken for an old one
        $maintain = self::arkhive(['maintain', '--config', $this->config()], $this->runEnv());
        self::assertSame(0, $maintain->exitCode, $maintain->output());
        self::assertCount(2, $this->snapshots());
    }

    private static function root(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    private function needsPause(): void
    {
        if ((getenv('E2E_SFTP_PAUSE') ?: '') === '' || (getenv('E2E_SFTP_RESUME') ?: '') === '') {
            self::markTestSkipped('E2E_SFTP_PAUSE / E2E_SFTP_RESUME are not set (run.sh sets them to docker compose pause/unpause)');
        }
    }

    /**
     * Freezes the SFTP server, runs $wait, and always resumes it.
     *
     * @param callable(): CliResult $wait
     */
    private function whilePaused(callable $wait): CliResult
    {
        $pause = self::exec(['sh', '-c', self::env('E2E_SFTP_PAUSE')]);
        self::assertSame(0, $pause->exitCode, $pause->output());
        try {
            return $wait();
        } finally {
            $resume = self::exec(['sh', '-c', self::env('E2E_SFTP_RESUME')]);
            self::assertSame(0, $resume->exitCode, $resume->output());
        }
    }

    /**
     * restic behind a bandwidth limit, so that a transfer is still running when the server freezes.
     */
    private function throttledRestic(string $limit): string
    {
        $path = "{$this->dir}/throttled-restic";
        file_put_contents($path, sprintf("#!/bin/sh\nexec %s %s 512 \"\$@\"\n", escapeshellarg(self::env('E2E_RESTIC')), $limit));
        chmod($path, 0755);

        return $path;
    }

    private function assertCleanAfterFailure(): void
    {
        self::assertFalse($this->lockHeld(), 'the ArkHive lock is released');
        self::assertSame([], glob("{$this->dir}/tmp/*") ?: [], 'no temporary file left');
    }

    private function lockHeld(): bool
    {
        foreach (glob("{$this->dir}/locks/arkhive-*.lock") ?: [] as $lock) {
            if (trim((string) @file_get_contents($lock)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $extra
     */
    private function config(array $extra = []): string
    {
        $values = [
            'BACKUP_PATHS' => $this->tree,
            'RESTIC_REPOSITORY' => $this->repository,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            'RESTIC_HOST' => 'e2e.example.com',
            ...$extra,
        ];
        if ($this->sftpCommand !== '') {
            $values['RESTIC_SFTP_COMMAND'] = $this->sftpCommand;
        }

        return $this->writeConfig("{$this->dir}/config", $values);
    }

    /**
     * @return array<string, string>
     */
    private function runEnv(): array
    {
        return ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'TMPDIR' => "{$this->dir}/tmp"];
    }

    /**
     * @return list<string>
     */
    private function snapshots(): array
    {
        $result = self::restic(['snapshots', '--json', '--no-lock'], $this->repository, $this->sftpCommand === '' ? null : $this->sftpCommand);
        self::assertSame(0, $result->exitCode, $result->stderr);
        $snapshots = json_decode($result->stdout, true);
        self::assertIsArray($snapshots);

        return array_map(static fn (array $s): string => (string) $s['id'], $snapshots);
    }
}
