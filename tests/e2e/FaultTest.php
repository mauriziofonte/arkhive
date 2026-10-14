<?php

declare(strict_types=1);

namespace Tests\e2e;

use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\CliResult;

/**
 * spec §13.6 fault injection: the SFTP server frozen mid-backup and mid-restore, a full repository
 * disk, a clock that jumped, a dump server that is down. Each case ends with a precise error, no
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

        self::assertSame(1, $result->exitCode, $result->output());
        self::assertMatchesRegularExpression('/error restic backup failed \(throttled-restic exit 1\): .*ssh command exited/', $result->stderr);
        self::assertStringContainsString('notice Check that the repository server is up and that this command works by hand: ' . $this->sftpCommand, $result->stderr);
        $this->assertCleanAfterFailure();
        self::assertSame([], $this->snapshots(), 'no snapshot from the interrupted upload');

        // once the server is back, the next run works: the lock restic could not remove is its own, stale
        $again = self::arkhive(['backup', '--config', $this->config()], $this->runEnv());
        self::assertSame(0, $again->exitCode, $again->output());
        self::assertCount(1, $this->snapshots());
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

        self::assertSame(1, $result->exitCode, $result->output());
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

            self::assertSame(1, $result->exitCode, $result->output());
            self::assertStringContainsString('no space left on device', $result->stderr);
            $this->assertCleanAfterFailure();
            self::assertSame([], $this->snapshots(), 'no snapshot on a full disk');
        } finally {
            self::exec(['umount', $full]);
        }
    }

    public function testADumpServerThatIsDownAbortsBeforeTheBackup(): void
    {
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        $config = $this->config([
            'DUMP_DIRECTORY' => "{$this->dir}/dumps",
            'WITH_MYSQL' => 'true',
            'MYSQL_HOST' => self::env('E2E_MYSQL_HOST'),
            // nothing listens on port 1: the server is down
            'MYSQL_PORT' => '1',
            'MYSQL_USER' => self::env('E2E_MYSQL_USER'),
            'MYSQL_PASSWORD' => self::env('E2E_MYSQL_PASSWORD'),
            'MYSQL_DATABASES' => 'shop',
        ]);

        $result = self::arkhive(['backup', '--config', $config], $this->runEnv());

        self::assertSame(1, $result->exitCode, $result->output());
        self::assertMatchesRegularExpression('/error .*(MySQL|MariaDB|mariadb|mysql).*/', $result->stderr);
        self::assertStringContainsString("Can't connect", $result->stderr);
        $this->assertCleanAfterFailure();
        self::assertSame([], glob("{$this->dir}/dumps/*/*") ?: [], 'no dump left');
        self::assertSame([], $this->snapshots(), 'a dump failure aborts before restic backup');
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
        self::assertSame(1, $doctor->exitCode, $doctor->output());
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
