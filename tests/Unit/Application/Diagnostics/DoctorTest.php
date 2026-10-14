<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Diagnostics;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\Application\Configuration\ConfigLocator;
use Mfonte\Arkhive\Application\Diagnostics\Doctor;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Diagnostics\Check;
use Mfonte\Arkhive\Domain\Diagnostics\CheckStatus;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Infrastructure\Restic\ResticFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;

/**
 * spec §10.6, on the sample repository (newest backup of web1.example.com e82340a7 at 14:17:53Z;
 * the clock reads 20:17:53Z, six hours later).
 */
final class DoctorTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private const ORDER = ['PHP', 'distribution', 'restic', 'configuration', 'removed 1.x keys', 'password file', 'SSH', 'repository', 'latest snapshot', 'last runs', 'dump space', 'clock', 'execution context', 'SELinux'];

    private FakeProcessRunner $runner;
    private FakeRestic $restic;
    private FixedClock $clock;
    private Cleanup $cleanup;
    private string $dir;

    /** @var list<string> */
    private array $binaries = ['restic', 'ssh', 'mariadb'];

    private string $osRelease = "ID=debian\nVERSION_ID=12\n";

    /** @var list<string> */
    private array $extensions = ['phar', 'json', 'mbstring', 'posix', 'pcntl', 'curl'];

    private string $phpVersion = '8.4.1';

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/locks");
        $this->runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->withRepository()->install($this->runner);
        $this->clock = new FixedClock(new DateTimeImmutable('2026-10-12 20:17:53', new DateTimeZone('UTC')));
        $this->cleanup = new Cleanup();
    }

    /**
     * @return array<string, Check>
     */
    private function diagnose(?string $config = null, ?ResticSettings $bareMetal = null, ?string $selinux = null): array
    {
        $dependencies = $this->dependencies();
        $doctor = new Doctor(
            $dependencies,
            new ResticFactory($this->runner, $dependencies),
            $this->runner,
            $this->cleanup,
            $this->clock,
            new ExecutionContext(false, false, false, LogFormat::Plain, 'no terminal'),
            ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks"],
            0,
            'web1.example.com',
            fn (string $extension): bool => in_array($extension, $this->extensions, true),
            static fn (int $pid): bool => $pid === 4242,
            $this->phpVersion,
            $selinux ?? "{$this->dir}/no-selinux",
        );
        $checks = $doctor->run(new ConfigLocator("{$this->dir}/cwd", "{$this->dir}/home"), $config, $bareMetal);
        self::assertSame(self::ORDER, array_map(static fn (Check $c): string => $c->name, $checks), 'the order of spec §10.6');
        $byName = [];
        foreach ($checks as $check) {
            $byName[$check->name] = $check;
        }

        return $byName;
    }

    private function dependencies(): Dependencies
    {
        return FakeBinaries::dependencies("{$this->dir}/bin", $this->binaries, $this->osRelease);
    }

    /**
     * @param array<string, Check> $checks
     *
     * @return array<string, string>
     */
    private static function statuses(array $checks): array
    {
        return array_map(static fn (Check $c): string => $c->status->value, $checks);
    }

    public function testAHealthyHost(): void
    {
        $checks = $this->diagnose($this->writeConfig());

        self::assertSame([
            'PHP' => 'ok', 'distribution' => 'ok', 'restic' => 'ok', 'configuration' => 'ok', 'removed 1.x keys' => 'ok', 'password file' => 'ok',
            'SSH' => 'skipped', 'repository' => 'ok', 'latest snapshot' => 'ok', 'last runs' => 'info', 'dump space' => 'skipped', 'clock' => 'ok', 'execution context' => 'info', 'SELinux' => 'skipped',
        ], self::statuses($checks));
        self::assertSame('PHP 8.4.1 with phar, json, mbstring, posix, pcntl, curl', $checks['PHP']->message);
        self::assertSame('not recorded: no state directory', $checks['last runs']->message, 'no RunJournal given');
        self::assertSame('debian 12, packages via apt install <package>', $checks['distribution']->message);
        self::assertSame("restic 0.19.1 at {$this->dir}/bin/restic", $checks['restic']->message);
        self::assertSame("{$this->dir}/config", $checks['configuration']->message);
        self::assertSame("{$this->dir}/password is private (mode 0600)", $checks['password file']->message);
        self::assertSame('/srv/restic-repo is reachable, the password opens it, no lock', $checks['repository']->message);
        self::assertSame('e82340a7 of web1.example.com at 2026-10-12 14:17 UTC (6 h ago)', $checks['latest snapshot']->message);
        self::assertSame('unattended (no terminal), log format plain', $checks['execution context']->message);
        self::assertSame(CheckStatus::Ok, Doctor::overall(array_values($checks)));
        self::assertSame([], $this->cleanup->runAll());
    }

    public function testMissingResticNamesTheInstallCommandAndSkipsTheRepository(): void
    {
        $this->binaries = ['ssh'];
        $this->osRelease = "ID=alpine\nVERSION_ID=3.20\n";

        $checks = $this->diagnose($this->writeConfig());

        self::assertSame(CheckStatus::Failed, $checks['restic']->status);
        self::assertSame('restic is needed for every backup and restore but was not found in PATH or the standard directories', $checks['restic']->message);
        self::assertSame('Run: apk add restic', $checks['restic']->hint);
        self::assertSame(CheckStatus::Skipped, $checks['repository']->status);
        self::assertSame(CheckStatus::Skipped, $checks['latest snapshot']->status);
        self::assertSame(CheckStatus::Failed, Doctor::overall(array_values($checks)));
    }

    public function testAnOldResticIsAFailure(): void
    {
        $this->restic->version = "restic 0.10.0 compiled with go1.15 on linux/amd64\n";

        $checks = $this->diagnose($this->writeConfig());

        self::assertSame(CheckStatus::Failed, $checks['restic']->status);
        self::assertStringStartsWith('restic 0.10.0 is too old', $checks['restic']->message);
    }

    public function testAnOlderSupportedResticNamesWhatItLacks(): void
    {
        $this->restic->version = "restic 0.12.1 compiled with go1.18 on linux/amd64\n";

        $checks = $this->diagnose($this->writeConfig());

        self::assertSame(CheckStatus::Ok, $checks['restic']->status);
        self::assertStringStartsWith('restic 0.12.1 at ', $checks['restic']->message);
        self::assertStringEndsWith('(no compression (repository format 1; format 2 needs restic 0.14.0 or newer); restores write sparse files in full; no restore progress)', $checks['restic']->message);
    }

    public function testACompressionSettingThatAnOldResticIgnoresIsAWarning(): void
    {
        $this->restic->version = "restic 0.12.1 compiled with go1.18 on linux/amd64\n";

        $checks = $this->diagnose($this->writeConfig(['RESTIC_COMPRESSION' => 'max']));

        self::assertSame(CheckStatus::Warning, $checks['restic']->status);
        self::assertStringEndsWith('no restore progress): RESTIC_COMPRESSION=max has no effect', $checks['restic']->message);
        self::assertSame('Install restic 0.14.0 or newer, or remove RESTIC_COMPRESSION', $checks['restic']->hint);

        $this->restic->version = "restic 0.14.0 compiled with go1.19 on linux/amd64\n";
        self::assertSame(CheckStatus::Ok, $this->diagnose($this->writeConfig(['RESTIC_COMPRESSION' => 'max']))['restic']->status);
        $this->restic->version = "restic 0.12.1 compiled with go1.18 on linux/amd64\n";
        self::assertSame(CheckStatus::Ok, $this->diagnose($this->writeConfig(['RESTIC_COMPRESSION' => 'auto']))['restic']->status);
    }

    public function testRemoved1xKeysAreListed(): void
    {
        $checks = $this->diagnose($this->writeConfig(['SSH_HOST' => 'backup.example.com', 'WITH_CRYPT' => 'true']));

        self::assertSame(CheckStatus::Failed, $checks['removed 1.x keys']->status);
        self::assertStringContainsString('  SSH_HOST: replaced by RESTIC_REPOSITORY', $checks['removed 1.x keys']->message);
        self::assertStringContainsString('  WITH_CRYPT: replaced by RESTIC_PASSWORD_FILE', $checks['removed 1.x keys']->message);
        self::assertSame(CheckStatus::Info, $checks['configuration']->status);
        self::assertStringEndsWith('(unusable until the removed keys are migrated)', $checks['configuration']->message);
        self::assertSame(CheckStatus::Skipped, $checks['password file']->status);
    }

    public function testAnInvalidConfigurationIsAFailure(): void
    {
        $checks = $this->diagnose($this->writeConfig(['RESTIC_COMPRESSION' => 'fast']));

        self::assertSame(CheckStatus::Failed, $checks['configuration']->status);
        self::assertStringContainsString('RESTIC_COMPRESSION must be auto, max or off, got "fast"', $checks['configuration']->message);
        self::assertSame(CheckStatus::Ok, $checks['removed 1.x keys']->status);
    }

    public function testUnknownKeysAreAWarning(): void
    {
        $checks = $this->diagnose($this->writeConfig(['RESTIC_KEEP_DAYLY' => '7']));

        self::assertSame(CheckStatus::Warning, $checks['configuration']->status);
        self::assertSame("{$this->dir}/config; unknown keys ignored: RESTIC_KEEP_DAYLY", $checks['configuration']->message);
    }

    public function testNoConfigurationAtAll(): void
    {
        $checks = $this->diagnose();

        self::assertSame(CheckStatus::Failed, $checks['configuration']->status);
        self::assertStringStartsWith('No ArkHive configuration file found. Looked for, in this order:', $checks['configuration']->message);
        self::assertSame(CheckStatus::Ok, $checks['restic']->status, 'restic from PATH is still checked');
        self::assertSame(CheckStatus::Skipped, $checks['password file']->status);
        self::assertSame(CheckStatus::Skipped, $checks['repository']->status);
    }

    public function testAnUnsafePasswordFileFailsAndSkipsTheRepository(): void
    {
        $config = $this->writeConfig();
        chmod("{$this->dir}/password", 0644);

        $checks = $this->diagnose($config);

        self::assertSame(CheckStatus::Failed, $checks['password file']->status);
        self::assertSame("RESTIC_PASSWORD_FILE {$this->dir}/password is readable by group or others (mode 0644)", $checks['password file']->message);
        self::assertSame("Run: chmod 600 {$this->dir}/password", $checks['password file']->hint);
        self::assertSame(CheckStatus::Skipped, $checks['repository']->status);
        self::assertSame([], $this->restic->callsOf('cat'));
    }

    public function testBareMetalReportsWhatIsMissing(): void
    {
        $this->binaries = [];
        $checks = $this->diagnose(null, new ResticSettings('sftp:backup@backup.example.com:/restic', '', 'ssh -i /root/.ssh/gone -p 23 backup@backup.example.com -s sftp'));

        self::assertSame(CheckStatus::Info, $checks['configuration']->status);
        self::assertSame(CheckStatus::Failed, $checks['restic']->status);
        self::assertSame('Run: apt install restic', $checks['restic']->hint);
        self::assertSame('--repo needs --password-file: the file holding the repository password', $checks['password file']->message);
        self::assertSame('ssh is needed to reach the SFTP repository but was not found', $checks['SSH']->message);
        self::assertSame('Run: apt install openssh-client', $checks['SSH']->hint);
    }

    public function testTheSshKeyOfTheSftpCommandIsChecked(): void
    {
        $key = "{$this->dir}/id_ed25519";
        $settings = static fn (string $command): ResticSettings => new ResticSettings('sftp:backup@backup.example.com:/restic', '/nonexistent', $command);

        $checks = $this->diagnose(null, $settings("ssh -i {$key} -p 23 backup@backup.example.com -s sftp"));
        self::assertSame("The SSH key {$key} (from -i in the SFTP command) does not exist", $checks['SSH']->message);

        file_put_contents($key, "placeholder key\n");
        chmod($key, 0644);
        $checks = $this->diagnose(null, $settings("ssh -p 23 -o IdentityFile={$key} backup@backup.example.com -s sftp"));
        self::assertSame("The SSH key {$key} is accessible by group or others (mode 0644): ssh refuses to use it", $checks['SSH']->message);
        self::assertSame("Run: chmod 600 {$key}", $checks['SSH']->hint);

        chmod($key, 0600);
        $checks = $this->diagnose(null, $settings("ssh -i{$key} -p 23 backup@backup.example.com -s sftp"));
        self::assertSame(CheckStatus::Ok, $checks['SSH']->status);
        self::assertSame("{$this->dir}/bin/ssh with the key {$key}", $checks['SSH']->message);

        $checks = $this->diagnose(null, new ResticSettings('sftp:backup@backup.example.com:/restic', '/nonexistent'));
        self::assertSame("{$this->dir}/bin/ssh, with this user's default keys (~/.ssh) and ssh_config", $checks['SSH']->message);
    }

    public function testAnUnreachableRepositoryFails(): void
    {
        $this->restic->catConfig = [1, '', FakeRestic::fixture('sftp-unreachable-0.19.stderr')];

        $checks = $this->diagnose($this->writeConfig());

        self::assertSame(CheckStatus::Failed, $checks['repository']->status);
        self::assertSame(CheckStatus::Skipped, $checks['latest snapshot']->status);
        self::assertSame(CheckStatus::Skipped, $checks['clock']->status);
    }

    /**
     * @param array<string, mixed> $lock
     */
    private function withLock(array $lock): void
    {
        $id = str_repeat('ab', 32);
        $this->restic->on['list'] = static fn (): array => [0, "{$id}\n", ''];
        $this->restic->on['cat'] = fn (array $args): array => $args[1] === 'config' ? $this->restic->catConfig : [0, (string) json_encode($lock), ''];
    }

    public function testAStaleLockIsAWarningWithTheUnlockCommand(): void
    {
        $this->withLock(['time' => '2026-10-12T19:00:00Z', 'exclusive' => true, 'hostname' => 'web1.example.com', 'username' => 'root', 'pid' => 999]);

        $checks = $this->diagnose($this->writeConfig());

        self::assertSame(CheckStatus::Warning, $checks['repository']->status);
        self::assertSame('/srv/restic-repo is reachable, but it has a stale lock: an exclusive lock held by root@web1.example.com (PID 999) since 2026-10-12 19:00:00 Z', $checks['repository']->message);
        self::assertSame("If no restic process is running anywhere for this repository, remove it with: restic -r '/srv/restic-repo' unlock (ArkHive never does this automatically)", $checks['repository']->hint);
        self::assertSame(CheckStatus::Warning, Doctor::overall(array_values($checks)));
    }

    public function testARunningPruneIsAWarningAndABackupIsFine(): void
    {
        $this->withLock(['time' => '2026-10-12T20:10:00Z', 'exclusive' => true, 'hostname' => 'web1.example.com', 'username' => 'root', 'pid' => 4242]);
        $checks = $this->diagnose($this->writeConfig());
        self::assertSame(CheckStatus::Warning, $checks['repository']->status);
        self::assertStringContainsString('is reachable, but it is locked: an exclusive lock held by root@web1.example.com (PID 4242)', $checks['repository']->message);

        $this->withLock(['time' => '2026-10-12T20:10:00Z', 'exclusive' => false, 'hostname' => 'web2.example.com', 'username' => 'root', 'pid' => 7]);
        $checks = $this->diagnose($this->writeConfig());
        self::assertSame('/srv/restic-repo is reachable, the password opens it, 1 shared lock(s) of running backups', $checks['repository']->message);
    }

    public function testARunningArkhiveIsMentioned(): void
    {
        $lock = Lock::forRepository('/srv/restic-repo', ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks"], new FixedClock(), 0);
        $lock->acquire('backup');
        try {
            $checks = $this->diagnose($this->writeConfig());
        } finally {
            $lock->release();
        }

        self::assertStringContainsString('no lock; an ArkHive backup (PID ' . getmypid() . ') has been running since 2026-10-12T10:20:30+02:00', $checks['repository']->message);
    }

    public function testALateOrMissingSnapshotIsAWarning(): void
    {
        $this->clock->now = new DateTimeImmutable('2026-10-14 08:00:00', new DateTimeZone('UTC'));
        $checks = $this->diagnose($this->writeConfig());
        self::assertSame(CheckStatus::Warning, $checks['latest snapshot']->status);
        self::assertSame('e82340a7 of web1.example.com at 2026-10-12 14:17 UTC (41 h ago): older than 26 hours', $checks['latest snapshot']->message);

        $checks = $this->diagnose($this->writeConfig(['RESTIC_HOST' => 'web9.example.com']));
        self::assertSame('no snapshot of web9.example.com yet', $checks['latest snapshot']->message);
        self::assertSame('Run: arkhive backup (and schedule it: arkhive schedule --systemd)', $checks['latest snapshot']->hint);
    }

    public function testASnapshotFromTheFutureMeansTheClockIsBehind(): void
    {
        $this->clock->now = new DateTimeImmutable('2026-10-12 12:00:00', new DateTimeZone('UTC'));

        $checks = $this->diagnose($this->writeConfig());

        self::assertSame(CheckStatus::Failed, $checks['clock']->status);
        self::assertSame('This clock is behind: the repository has an entry dated 2026-10-12 14:17:53 UTC, 2 h in the future', $checks['clock']->message);
        self::assertStringStartsWith('Synchronise the clock (timedatectl set-ntp true', (string) $checks['clock']->hint);
    }

    public function testASmallSkewIsTolerated(): void
    {
        $this->clock->now = new DateTimeImmutable('2026-10-12 14:15:00', new DateTimeZone('UTC'));

        self::assertSame(CheckStatus::Ok, $this->diagnose($this->writeConfig())['clock']->status);
    }

    private function mysqlServer(string $size): void
    {
        $this->runner->on('mariadb', static function (Command $command) use ($size): array {
            foreach ($command->argv as $arg) {
                if ($arg === '--execute=SHOW DATABASES') {
                    return [0, "shop\nmysql\n", ''];
                }
                if (str_starts_with($arg, '--execute=SELECT COALESCE')) {
                    return [0, "{$size}\n", ''];
                }
            }

            return [1, '', "ERROR 2002 (HY000): Can't connect to server on 'db.example.com' (115)\n"];
        });
    }

    /**
     * @return array<string, string>
     */
    private function mysqlConfig(): array
    {
        return ['WITH_MYSQL' => 'true', 'MYSQL_HOST' => 'db.example.com', 'MYSQL_USER' => 'backup', 'MYSQL_PASSWORD' => 'mysql-password-placeholder', 'MYSQL_DATABASES' => '*', 'DUMP_DIRECTORY' => "{$this->dir}/dumps"];
    }

    public function testDumpSpaceComparesTheEstimateWithTheFreeSpace(): void
    {
        $this->mysqlServer('1048576');
        $checks = $this->diagnose($this->writeConfig($this->mysqlConfig()));
        self::assertSame(CheckStatus::Ok, $checks['dump space']->status);
        self::assertMatchesRegularExpression('#^about 1\.00 MiB of dumps, [\d.]+ [KMGT]?i?B free in ' . preg_quote($this->dir, '#') . ' \(DUMP_DIRECTORY .+/dumps is created by the first backup\)$#', $checks['dump space']->message);
        self::assertSame([], $this->cleanup->runAll(), 'the credentials file is gone');

        $this->mysqlServer((string) PHP_INT_MAX);
        $checks = $this->diagnose($this->writeConfig($this->mysqlConfig()));
        self::assertSame(CheckStatus::Failed, $checks['dump space']->status);
        self::assertStringStartsWith('Not enough space: about ', $checks['dump space']->message);
        self::assertSame('Free some space, or point DUMP_DIRECTORY to a larger filesystem', $checks['dump space']->hint);
    }

    public function testAnUnreachableDatabaseServerFailsTheDumpCheck(): void
    {
        $this->runner->on('mariadb', static fn (): array => [1, '', "ERROR 2002 (HY000): Can't connect to server on 'db.example.com' (115)\n"]);

        $checks = $this->diagnose($this->writeConfig($this->mysqlConfig()));

        self::assertSame(CheckStatus::Failed, $checks['dump space']->status);
        self::assertStringStartsWith('Cannot estimate the dumps: ', $checks['dump space']->message);
        self::assertStringContainsString("Can't connect to server on 'db.example.com'", $checks['dump space']->message);
    }

    public function testPhpProblems(): void
    {
        $this->extensions = ['phar', 'json', 'mbstring', 'posix', 'pcntl'];
        $checks = $this->diagnose($this->writeConfig());
        self::assertSame(CheckStatus::Warning, $checks['PHP']->status);
        self::assertSame('PHP 8.4.1 has neither curl nor openssl: self-update cannot download releases', $checks['PHP']->message);
        self::assertSame('Run: apt install php8.4-curl', $checks['PHP']->hint);

        $this->extensions = ['phar', 'json', 'posix', 'curl'];
        $checks = $this->diagnose($this->writeConfig());
        self::assertSame('PHP 8.4.1 lacks the extension(s) mbstring, pcntl', $checks['PHP']->message);
        self::assertSame('Run: apt install php8.4-mbstring php8.4-cli', $checks['PHP']->hint);

        $this->phpVersion = '8.2.7';
        $checks = $this->diagnose($this->writeConfig());
        self::assertSame('PHP 8.2.7 is too old: ArkHive needs PHP 8.3 or newer', $checks['PHP']->message);
        self::assertStringContainsString('packages.sury.org', (string) $checks['PHP']->hint);
    }

    public function testSelinuxEnforcingIsMentioned(): void
    {
        file_put_contents("{$this->dir}/enforce", "1\n");

        $checks = $this->diagnose($this->writeConfig(), null, "{$this->dir}/enforce");

        self::assertSame(CheckStatus::Info, $checks['SELinux']->status);
        self::assertSame('After moving restored files into place, run: restorecon -R <path>', $checks['SELinux']->hint);
    }

    public function testTheOverallStatus(): void
    {
        $ok = new Check('a', CheckStatus::Ok, '');
        $info = new Check('b', CheckStatus::Info, '');
        $warning = new Check('c', CheckStatus::Warning, '');
        $failed = new Check('d', CheckStatus::Failed, '');

        self::assertSame(CheckStatus::Ok, Doctor::overall([$ok, $info, new Check('e', CheckStatus::Skipped, '')]));
        self::assertSame(CheckStatus::Warning, Doctor::overall([$ok, $warning]));
        self::assertSame(CheckStatus::Failed, Doctor::overall([$warning, $failed, $ok]));
        self::assertSame(['check' => 'c', 'status' => 'warning', 'message' => '', 'hint' => null], $warning->toArray());
    }
}
