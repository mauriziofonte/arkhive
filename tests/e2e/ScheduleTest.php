<?php

declare(strict_types=1);

namespace Tests\e2e;

/**
 * spec §11 scheduling helpers, end to end: the installed PHAR prints its own schedule, and the
 * printed cron line and systemd ExecStart run a real backup in the environment cron and systemd give
 * them (PATH of the standard directories only; INVOCATION_ID under systemd).
 */
final class ScheduleTest extends E2eTestCase
{
    private string $dir;
    private string $installed;
    private string $config;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/bin");
        mkdir("{$this->dir}/site");
        file_put_contents("{$this->dir}/site/index.php", "<?php echo 'hello';\n");
        $this->installed = "{$this->dir}/bin/arkhive";
        copy(self::env('E2E_PHAR'), $this->installed);
        chmod($this->installed, 0755);
        $repository = "{$this->dir}/repository";
        $this->arkhiveInit($this->dir, $repository, null);
        $this->config = $this->writeConfig("{$this->dir}/config", [
            'BACKUP_PATHS' => "{$this->dir}/site",
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            'RESTIC_BINARY' => self::env('E2E_RESTIC'),
            'RESTIC_HOST' => 'e2e.example.com',
        ]);
    }

    public function testTheCronLineRunsABackup(): void
    {
        $printed = self::exec([$this->installed, 'schedule', '--cron', '--config', $this->config], ['XDG_STATE_HOME' => "{$this->dir}/state"]);
        self::assertSame(0, $printed->exitCode, $printed->output());
        $root = posix_geteuid() === 0;
        $line = (string) preg_grep('/ backup --config /', explode("\n", $printed->stdout))[3];
        // an executable PHAR with php in the standard directories runs by itself
        self::assertMatchesRegularExpression('#^30 2 \* \* \* ' . ($root ? 'root ' : '') . 'nice -n 10 ' . preg_quote($this->installed, '#') . ' backup --config ' . preg_quote($this->config, '#') . ' --log-file \S+ >/dev/null 2>&1$#', $line);

        $command = str_replace('\%', '%', (string) preg_replace('/^(\S+ ){5}' . ($root ? 'root ' : '') . '/', '', $line));
        $log = "{$this->dir}/state/arkhive/arkhive.log";
        // as root the line logs to /var/log/arkhive.log: kept inside the test directory here
        $command = str_replace('/var/log/arkhive.log', $log, $command);
        mkdir(dirname($log), 0700, true);
        // cron's own environment: PATH=/usr/bin:/bin, then the PATH line of the table
        $result = self::exec(['/bin/sh', '-c', $command], ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => (string) getenv('HOME')]);

        self::assertSame(0, $result->exitCode, $result->output() . (string) @file_get_contents($log));
        self::assertSame('', $result->output(), 'all output goes to the log file');
        self::assertMatchesRegularExpression('/^\S+ success Snapshot [0-9a-f]{8} saved: /m', (string) file_get_contents($log));
    }

    public function testTheSystemdExecStartRunsABackupForJournald(): void
    {
        $printed = self::exec([$this->installed, 'schedule', '--systemd', '--config', $this->config]);
        self::assertSame(0, $printed->exitCode, $printed->output());
        self::assertSame(1, preg_match('/^ExecStart=(.+ backup --config .+)$/m', $printed->stdout, $m), $printed->stdout);

        // the paths here need no quoting: the ExecStart value splits on spaces like systemd does
        $result = self::exec(explode(' ', $m[1]), [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => (string) getenv('HOME'),
            'INVOCATION_ID' => bin2hex(random_bytes(16)),
        ]);

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertMatchesRegularExpression('/^<6>Snapshot [0-9a-f]{8} saved: /m', $result->stderr);
        foreach (explode("\n", trim($result->stderr)) as $line) {
            self::assertMatchesRegularExpression('/^<[0-7]>\S/', $line, 'journald lines: a priority prefix, no timestamp');
        }
    }
}
