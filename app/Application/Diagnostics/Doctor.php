<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Diagnostics;

use Closure;
use DateTimeImmutable;
use Mfonte\Arkhive\Application\Configuration\ConfigFactory;
use Mfonte\Arkhive\Application\Configuration\ConfigLoader;
use Mfonte\Arkhive\Application\Configuration\ConfigLocator;
use Mfonte\Arkhive\Application\Configuration\ConfigParser;
use Mfonte\Arkhive\Application\Configuration\PasswordFile;
use Mfonte\Arkhive\Application\Database\MysqlDumper;
use Mfonte\Arkhive\Application\Database\PgsqlDumper;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Restore\TargetDirectory;
use Mfonte\Arkhive\Application\Run\RunJournal;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\ConfigFileAccess;
use Mfonte\Arkhive\Domain\Configuration\RemovedKeys;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Diagnostics\Check;
use Mfonte\Arkhive\Domain\Diagnostics\CheckStatus;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Domain\Repository\RepositoryKey;
use Mfonte\Arkhive\Domain\Repository\ResticCapabilities;
use Mfonte\Arkhive\Domain\Repository\SftpCommand;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Domain\System\Dependency;
use Mfonte\Arkhive\Domain\System\InstallHints;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;

/**
 * spec §10.6: checks the environment and the repository in a fixed order and says how to fix what
 * is wrong. It never changes anything; a check that cannot run because an earlier one failed is skipped.
 */
final class Doctor
{
    /** a daily backup older than this is late */
    public const SNAPSHOT_MAX_AGE_HOURS = 26;

    /** a snapshot or lock dated further in the future than this means the local clock is behind */
    public const CLOCK_SKEW_SECONDS = 300;

    /** extensions that make self-update work: one of them is enough */
    public const DOWNLOAD_EXTENSIONS = ['curl', 'openssl'];

    /** @var Closure(string): bool */
    private Closure $extensionLoaded;

    /** @var Closure(int): bool */
    private Closure $processExists;

    /** @var list<Check> */
    private array $checks = [];

    private ?Config $config = null;
    private ?ResticSettings $settings = null;
    private ?SnapshotStore $restic = null;
    private bool $passwordOk = false;
    private bool $reachable = false;

    /** @var list<Snapshot> */
    private array $snapshots = [];

    /** @var list<DateTimeImmutable> times recorded by the repository (snapshots, locks) */
    private array $repositoryTimes = [];

    /**
     * @param array<string, string>        $environment     for the ArkHive lock directory
     * @param (Closure(string): bool)|null $extensionLoaded
     * @param (Closure(int): bool)|null    $processExists
     */
    public function __construct(
        private readonly Dependencies $dependencies,
        private readonly SnapshotStoreFactory $resticFactory,
        private readonly ProcessRunner $runner,
        private readonly Cleanup $cleanup,
        private readonly Clock $clock,
        private readonly ExecutionContext $context,
        private readonly array $environment,
        private readonly int $uid,
        private readonly string $hostname,
        ?Closure $extensionLoaded = null,
        ?Closure $processExists = null,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly string $selinuxEnforce = '/sys/fs/selinux/enforce',
        private readonly ?RunJournal $runs = null,
        /** @var array<string, string> log target => socket, instead of LogTarget::socket() (tests) */
        private readonly array $logSockets = [],
    ) {
        $this->extensionLoaded = $extensionLoaded ?? static fn (string $extension): bool => extension_loaded($extension);
        // EPERM: the process exists, owned by another user
        $this->processExists = $processExists ?? static fn (int $pid): bool => function_exists('posix_kill') && (posix_kill($pid, 0) || posix_get_last_error() === 1);
    }

    /**
     * @param string|null         $configOption --config, when given
     * @param ResticSettings|null $bareMetal    --repo/--password-file, when given
     *
     * @return list<Check>
     */
    public function run(ConfigLocator $locator, ?string $configOption, ?ResticSettings $bareMetal): array
    {
        $this->checks = [];
        $this->config = $this->settings = $this->restic = null;
        $this->passwordOk = $this->reachable = false;
        $this->snapshots = $this->repositoryTimes = [];
        $this->php();
        $this->distro();
        $configChecks = $this->configuration($locator, $configOption, $bareMetal);
        $this->resticBinary();
        array_push($this->checks, ...$configChecks);
        $this->passwordFile();
        $this->ssh();
        $this->repository();
        $this->latestSnapshot();
        $this->lastRuns();
        $this->dumpSpace();
        $this->clockSkew();
        $this->add('execution context', CheckStatus::Info, $this->context->describe());
        $this->logTarget();
        $this->selinux();

        return $this->checks;
    }

    /**
     * @param list<Check> $checks
     */
    public static function overall(array $checks): CheckStatus
    {
        $statuses = array_map(static fn (Check $check): CheckStatus => $check->status, $checks);

        return match (true) {
            in_array(CheckStatus::Failed, $statuses, true) => CheckStatus::Failed,
            in_array(CheckStatus::Warning, $statuses, true) => CheckStatus::Warning,
            default => CheckStatus::Ok,
        };
    }

    private function add(string $name, CheckStatus $status, string $message, ?string $hint = null): void
    {
        $this->checks[] = new Check($name, $status, $message, $hint);
    }

    private function php(): void
    {
        $required = function_exists('arkhive_required_extensions') ? arkhive_required_extensions() : ['phar', 'json', 'mbstring', 'posix', 'pcntl'];
        $missing = array_values(array_filter($required, fn (string $e): bool => !($this->extensionLoaded)($e)));
        $download = array_values(array_filter(self::DOWNLOAD_EXTENSIONS, fn (string $e): bool => ($this->extensionLoaded)($e)));
        $loaded = [...array_values(array_diff($required, $missing)), ...$download];
        if (version_compare($this->phpVersion, '8.3.0', '<')) {
            $distro = $this->dependencies->distro();
            $problem = (string) arkhive_php_version_problem($this->phpVersion, "ID={$distro->id}\nID_LIKE=" . implode(' ', $distro->idLike) . "\n");
            $lines = explode(PHP_EOL, $problem);
            $this->add('PHP', CheckStatus::Failed, "PHP {$this->phpVersion} is too old: ArkHive needs PHP 8.3 or newer", $lines[array_key_last($lines)]);

            return;
        }
        if ($missing !== []) {
            $this->add('PHP', CheckStatus::Failed, "PHP {$this->phpVersion} lacks the extension(s) " . implode(', ', $missing), InstallHints::forPhpExtensions($missing, $this->dependencies->distro(), $this->phpVersion));

            return;
        }
        if ($download === []) {
            $this->add('PHP', CheckStatus::Warning, "PHP {$this->phpVersion} has neither curl nor openssl: self-update cannot download releases", InstallHints::forPhpExtensions(['curl'], $this->dependencies->distro(), $this->phpVersion));

            return;
        }
        $this->add('PHP', CheckStatus::Ok, "PHP {$this->phpVersion} with " . implode(', ', $loaded));
    }

    /**
     * Where this command's events go, with the configuration's LOG_TARGET unless --log-target was given.
     */
    private function logTarget(): void
    {
        [$target, $reason] = $this->config?->logTarget === null || $this->context->logTargetReason === '--log-target'
            ? [$this->context->logTarget, $this->context->logTargetReason]
            : [$this->config->logTarget, 'LOG_TARGET'];
        $what = $target === LogTarget::Syslog ? 'syslog, facility ' . ($this->config->syslogFacility ?? SyslogFacility::Daemon)->value : $target->value;
        $socket = $this->logSockets[$target->value] ?? $target->socket();
        if ($socket !== null && !file_exists($socket)) {
            $this->add('log target', CheckStatus::Warning, "{$what} ({$reason}), but {$socket} does not exist: events go to stderr", $target === LogTarget::Syslog ? 'Start a syslog daemon (rsyslog, syslog-ng), or set LOG_TARGET=stderr' : 'The journal is only there under systemd: set LOG_TARGET=auto or stderr');

            return;
        }
        $this->add('log target', CheckStatus::Info, "{$what} ({$reason})" . ($target === LogTarget::Stderr ? '' : ", via {$socket}"));
    }

    private function distro(): void
    {
        $distro = $this->dependencies->distro();
        $manager = $distro->packageManager();
        if ($manager === null) {
            $this->add('distribution', CheckStatus::Warning, "{$distro->describe()}: unknown package manager, install hints are generic", 'Install restic, ssh and the database clients with your package manager');

            return;
        }
        $this->add('distribution', CheckStatus::Ok, "{$distro->describe()}, packages via " . $manager->install(['<package>']));
    }

    /**
     * Loads the configuration first (RESTIC_BINARY comes from it) but returns its checks, which are
     * reported after the restic binary as in the spec's order.
     *
     * @return list<Check>
     */
    private function configuration(ConfigLocator $locator, ?string $configOption, ?ResticSettings $bareMetal): array
    {
        if ($bareMetal !== null && $configOption === null) {
            $this->settings = $bareMetal;

            return [
                new Check('configuration', CheckStatus::Info, 'none used: the repository comes from --repo (bare-metal recovery)'),
                new Check('config file', CheckStatus::Skipped, 'no configuration'),
                new Check('removed 1.x keys', CheckStatus::Skipped, 'no configuration'),
            ];
        }
        try {
            $path = $locator->locate($configOption);
            $values = ConfigParser::parseFile($path);
        } catch (ArkhiveException $e) {
            return [
                new Check('configuration', CheckStatus::Failed, $e->getMessage(), $e->hint() ?? 'On a host with no configuration use: arkhive doctor --repo <repository> --password-file <file>'),
                new Check('config file', CheckStatus::Skipped, 'no configuration'),
                new Check('removed 1.x keys', CheckStatus::Skipped, 'no configuration'),
            ];
        }
        $fileCheck = self::configFile(ConfigLoader::access($path, $values, $this->uid), $path);
        $removed = RemovedKeys::found($values);
        $removedCheck = $removed === []
            ? new Check('removed 1.x keys', CheckStatus::Ok, 'none')
            : new Check('removed 1.x keys', CheckStatus::Failed, RemovedKeys::message($removed, $path), 'Migration guide: ' . RemovedKeys::MIGRATION_URL);
        try {
            $this->config = ConfigFactory::create($values, $path, $this->hostname, false);
        } catch (ArkhiveException $e) {
            return [
                new Check('configuration', $removed === [] ? CheckStatus::Failed : CheckStatus::Info, $removed === [] ? $e->getMessage() : "{$path} (unusable until the removed keys are migrated)", $e->hint()),
                $fileCheck,
                $removedCheck,
            ];
        }
        $this->settings = $bareMetal ?? $this->config->restic;
        $unknown = $this->config->unknownKeys === [] ? '' : '; unknown keys ignored: ' . implode(', ', $this->config->unknownKeys);

        return [
            new Check('configuration', $unknown === '' ? CheckStatus::Ok : CheckStatus::Warning, $path . $unknown, $unknown === '' ? null : 'Remove or correct the unknown keys (typos are ignored silently otherwise)'),
            $fileCheck,
            $removedCheck,
        ];
    }

    /**
     * Who else can read or change the configuration. Readable secrets fail here although a run
     * only warns about them during the 2.0 grace period (ConfigFileAccess::ENFORCE_PRIVATE).
     */
    private static function configFile(?ConfigFileAccess $access, string $path): Check
    {
        if ($access === null) {
            return new Check('config file', CheckStatus::Skipped, "{$path} cannot be inspected");
        }
        $mode = sprintf('mode %04o, owner uid %d', $access->mode, $access->owner);
        $problem = $access->problem();
        if ($problem !== null) {
            return new Check('config file', CheckStatus::Failed, $problem, $access->hint());
        }
        if ($access->readableByOthers() || $access->owner !== $access->user) {
            return new Check('config file', CheckStatus::Warning, "{$path} ({$mode}) holds no passwords but is not private: it names the repository and the password file", $access->hint());
        }

        return new Check('config file', CheckStatus::Ok, "{$path} is private ({$mode})");
    }

    private function resticBinary(): void
    {
        $settings = $this->settings ?? new ResticSettings('', '');
        try {
            $restic = $this->resticFactory->create($settings, 'for every backup and restore');
            $version = $restic->requireSupportedVersion();
            $this->restic = $restic;
            $capabilities = $restic->capabilities();
            $limits = $capabilities->limitations();
            $found = "restic {$version} at {$restic->binary()}" . ($limits === [] ? '' : ' (' . implode('; ', $limits) . ')');
            $compression = $this->config?->restic->compression;
            if ($compression !== null && $compression !== 'auto' && !$capabilities->compression()) {
                $this->add('restic', CheckStatus::Warning, "{$found}: RESTIC_COMPRESSION={$compression} has no effect", 'Install restic ' . ResticCapabilities::REPOSITORY_V2 . ' or newer, or remove RESTIC_COMPRESSION');
            } elseif (!$capabilities->recommended()) {
                $this->add(
                    'restic',
                    CheckStatus::Info,
                    $found,
                    'restic before ' . ResticCapabilities::RECOMMENDED . ' works, but maintain and drill on a damaged repository can take minutes of retries and report less: prefer the static restic ' . ResticCapabilities::RECOMMENDED_LINE . ' binary from https://github.com/restic/restic/releases (check it against SHA256SUMS, then set RESTIC_BINARY)',
                );
            } else {
                $this->add('restic', CheckStatus::Ok, $found);
            }
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $this->add('restic', CheckStatus::Failed, $e->getMessage(), $e->hint());
        }
    }

    private function passwordFile(): void
    {
        if ($this->settings === null) {
            $this->add('password file', CheckStatus::Skipped, 'no repository settings');

            return;
        }
        $file = $this->settings->passwordFile;
        if ($file === '') {
            $this->add('password file', CheckStatus::Failed, '--repo needs --password-file: the file holding the repository password', 'Write the password to a file only you can read (chmod 600) and pass it with --password-file');

            return;
        }
        try {
            PasswordFile::assertSafe($file, $this->config === null ? '--password-file' : 'RESTIC_PASSWORD_FILE');
            $this->passwordOk = true;
            $this->add('password file', CheckStatus::Ok, sprintf('%s is private (mode %04o)', $file, fileperms($file) & 0777));
        } catch (ArkhiveException $e) {
            $this->add('password file', CheckStatus::Failed, $e->getMessage(), $e->hint() ?? 'Put the repository password in that file and chmod 600 it; keep a copy off this host');
        }
    }

    private function ssh(): void
    {
        $settings = $this->settings;
        if ($settings === null || (!str_starts_with($settings->repository, 'sftp:') && $settings->sftpCommand === null)) {
            $this->add('SSH', CheckStatus::Skipped, 'the repository is not reached over SFTP');

            return;
        }
        $words = ConfigFactory::splitWords($settings->sftpCommand ?? 'ssh');
        $binary = $words[0] ?? 'ssh';
        $found = $binary === 'ssh' ? $this->dependencies->find(Dependency::Ssh) : (is_executable($binary) ? $binary : $this->dependencies->find(Dependency::Ssh));
        if ($found === null) {
            $this->add('SSH', CheckStatus::Failed, 'ssh is needed to reach the SFTP repository but was not found', InstallHints::forDependency(Dependency::Ssh, $this->dependencies->distro()));

            return;
        }
        $key = self::identityFile($words);
        if ($key === null) {
            $this->sshReady("{$found}, with this user's default keys (~/.ssh) and ssh_config");

            return;
        }
        clearstatcache(true, $key);
        if (!is_file($key)) {
            $this->add('SSH', CheckStatus::Failed, "The SSH key {$key} (from -i in the SFTP command) does not exist", 'Restore the key from your safe copy, or generate one (ssh-keygen -t ed25519) and authorise it on the server');

            return;
        }
        if (!is_readable($key)) {
            $this->add('SSH', CheckStatus::Failed, "The SSH key {$key} is not readable by this user (uid {$this->uid})", "Run ArkHive as the key's owner, or: chown {$this->uid} {$key}");

            return;
        }
        $mode = fileperms($key) & 0777;
        if (($mode & 0077) !== 0) {
            $this->add('SSH', CheckStatus::Failed, sprintf('The SSH key %s is accessible by group or others (mode %04o): ssh refuses to use it', $key, $mode), "Run: chmod 600 {$key}");

            return;
        }
        $this->sshReady("{$found} with the key {$key}");
    }

    /**
     * ssh is usable; a RESTIC_SFTP_COMMAND that does not run ssh itself (a wrapper) gets no keepalives
     * from ArkHive, so a server that stops answering would hang the run.
     */
    private function sshReady(string $message): void
    {
        $command = $this->settings?->sftpCommand;
        $missing = $command === null || SftpCommand::runsSsh($command) ? [] : SftpCommand::missingOptions($command);
        if ($missing === []) {
            $this->add('SSH', CheckStatus::Ok, $message);

            return;
        }
        $options = implode(' ', array_map(static fn (string $name): string => "-o {$name}=" . SftpCommand::OPTIONS[$name], $missing));
        $this->add(
            'SSH',
            CheckStatus::Warning,
            "{$message}; RESTIC_SFTP_COMMAND does not start with ssh, so ArkHive cannot add {$options}: a server that stops answering would hang the run",
            "Start RESTIC_SFTP_COMMAND with ssh, or pass {$options} to the ssh your command runs",
        );
    }

    /**
     * @param list<string> $words
     */
    private static function identityFile(array $words): ?string
    {
        foreach ($words as $i => $word) {
            if ($word === '-i' && isset($words[$i + 1])) {
                return $words[$i + 1];
            }
            if (str_starts_with($word, '-i') && strlen($word) > 2) {
                return substr($word, 2);
            }
            if ($word === '-o' && isset($words[$i + 1]) && preg_match('/^IdentityFile[= ](.+)$/i', $words[$i + 1], $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function repository(): void
    {
        $settings = $this->settings;
        if ($settings === null || $this->restic === null || !$this->passwordOk) {
            $this->add('repository', CheckStatus::Skipped, 'needs restic and a valid password file');

            return;
        }
        try {
            $this->restic->checkReachable();
            $locks = $this->restic->locks();
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $this->add('repository', CheckStatus::Failed, $e->getMessage(), $e->hint());

            return;
        }

        $this->reachable = true;
        $now = $this->clock->now();
        $stale = [];
        $active = [];
        foreach ($locks as $lock) {
            $this->repositoryTimes[] = $lock->time;
            if ($lock->isStale($now, $this->hostname, $this->processExists)) {
                $stale[] = $lock->describe();
            } else {
                $active[] = $lock;
            }
        }
        $local = Lock::forRepository($settings->repository, $this->environment, $this->clock, $this->uid)->holder();
        $running = $local === [] ? '' : sprintf('; an ArkHive %s (PID %s) has been running since %s', $local['operation'] ?? 'run', $local['pid'] ?? '?', $local['started'] ?? '?');

        if ($stale !== []) {
            $this->add('repository', CheckStatus::Warning, "{$settings->repository} is reachable, but it has a stale lock: " . implode('; ', $stale) . $running, 'If no restic process is running anywhere for this repository, remove it with: restic -r ' . escapeshellarg($settings->repository) . ' unlock (ArkHive never does this automatically)');

            return;
        }
        $exclusive = array_filter($active, static fn ($lock): bool => $lock->exclusive);
        if ($exclusive !== []) {
            $this->add('repository', CheckStatus::Warning, "{$settings->repository} is reachable, but it is locked: " . implode('; ', array_map(static fn ($lock): string => $lock->describe(), $exclusive)) . $running, 'A prune or check is running: backups and restores wait for it or fail on the lock');

            return;
        }
        $using = $active === [] ? 'no lock' : count($active) . ' shared lock(s) of running backups';
        $this->add('repository', CheckStatus::Ok, "{$settings->repository} is reachable, the password opens it, {$using}{$running}");
    }

    private function latestSnapshot(): void
    {
        if ($this->restic === null || !$this->reachable) {
            $this->add('latest snapshot', CheckStatus::Skipped, 'the repository is not available');

            return;
        }
        try {
            $this->snapshots = $this->restic->snapshots();
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $this->add('latest snapshot', CheckStatus::Failed, $e->getMessage(), $e->hint());

            return;
        }
        foreach ($this->snapshots as $snapshot) {
            $this->repositoryTimes[] = $snapshot->time;
        }
        $host = $this->config?->restic->host;
        $all = new Snapshots($this->snapshots);
        if ($host === null && in_array($this->hostname, $all->hosts(), true)) {
            $host = $this->hostname;
        }
        $latest = $all->backups()->forHost($host)->snapshots[0] ?? null;
        $of = $host === null ? '' : " of {$host}";
        if ($latest === null) {
            $this->add('latest snapshot', CheckStatus::Warning, "no snapshot{$of} yet", 'Run: arkhive backup (and schedule it: arkhive schedule --systemd)');

            return;
        }
        $now = $this->clock->now();
        $age = $latest->ageHours($now);
        $text = sprintf('%s%s at %s (%s ago)', $latest->shortId(), $of, $latest->time->setTimezone($now->getTimezone())->format('Y-m-d H:i T'), $latest->age($now));
        if ($age > self::SNAPSHOT_MAX_AGE_HOURS) {
            $this->add('latest snapshot', CheckStatus::Warning, "{$text}: older than " . self::SNAPSHOT_MAX_AGE_HOURS . ' hours', 'Check that the backup timer or cron job runs: systemctl list-timers arkhive-backup.timer, or the cron log');

            return;
        }
        $this->add('latest snapshot', CheckStatus::Ok, $text);
    }

    /**
     * The last backup, maintain and drill recorded on this host: a failed one is a warning, even
     * when an older snapshot still looks recent enough.
     */
    private function lastRuns(): void
    {
        $location = $this->runs?->location();
        if ($this->runs === null || $location === null) {
            $this->add('last runs', CheckStatus::Info, 'not recorded: no state directory');

            return;
        }
        $zone = $this->clock->now()->getTimezone();
        $described = [];
        $failed = null;
        foreach ($this->runs->states($this->settings === null ? null : RepositoryKey::of($this->settings->repository)) as $job => $state) {
            if ($state->last === null) {
                continue;
            }
            $described[] = "{$job} {$state->last->describe($zone)}";
            if ($failed === null && !$state->last->succeeded()) {
                $failed = $job;
            }
        }
        if ($described === []) {
            $this->add('last runs', CheckStatus::Info, "none recorded yet in {$location}");

            return;
        }
        if ($failed !== null) {
            $this->add('last runs', CheckStatus::Warning, implode('; ', $described), "Read the log of that run (journalctl -u arkhive-{$failed}.service, or the cron mail or --log-file), fix the cause, then run: arkhive {$failed}");

            return;
        }
        $this->add('last runs', CheckStatus::Ok, implode('; ', $described));
    }

    private function dumpSpace(): void
    {
        $config = $this->config;
        if ($config === null || !$config->hasDumps() || $config->dumpDirectory === null) {
            $this->add('dump space', CheckStatus::Skipped, $config === null ? 'no configuration' : 'no database dumps configured');

            return;
        }
        $needed = 0;
        $dumpers = [];
        try {
            if ($config->mysql !== null) {
                $dumpers[] = $mysql = new MysqlDumper($config->mysql, $this->runner, $this->dependencies, $this->cleanup);
                $needed += $mysql->estimate($mysql->databases());
            }
            if ($config->pgsql !== null) {
                $dumpers[] = $pgsql = new PgsqlDumper($config->pgsql, $this->runner, $this->dependencies, $this->cleanup);
                $needed += $pgsql->estimate($pgsql->databases());
            }
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $this->add('dump space', CheckStatus::Failed, 'Cannot estimate the dumps: ' . $e->getMessage(), $e->hint());

            return;
        } finally {
            foreach ($dumpers as $dumper) {
                $dumper->close();
            }
        }
        $target = new TargetDirectory($config->dumpDirectory);
        $free = $target->freeSpace();
        $where = $target->exists() ? $config->dumpDirectory : $target->existingAncestor() . " (DUMP_DIRECTORY {$config->dumpDirectory} is created by the first backup)";
        if ($free === null) {
            $this->add('dump space', CheckStatus::Warning, "Cannot read the free space of {$where}");

            return;
        }
        $text = sprintf('about %s of dumps, %s free in %s', Bytes::format($needed), Bytes::format($free), $where);
        if ($needed > $free) {
            $this->add('dump space', CheckStatus::Failed, "Not enough space: {$text}", 'Free some space, or point DUMP_DIRECTORY to a larger filesystem');

            return;
        }
        $this->add('dump space', CheckStatus::Ok, $text);
    }

    private function clockSkew(): void
    {
        if ($this->repositoryTimes === []) {
            $this->add('clock', CheckStatus::Skipped, 'nothing in the repository to compare the clock with');

            return;
        }
        $now = $this->clock->now();
        $newest = max(array_map(static fn (DateTimeImmutable $t): int => $t->getTimestamp(), $this->repositoryTimes));
        $ahead = $newest - $now->getTimestamp();
        if ($ahead > self::CLOCK_SKEW_SECONDS) {
            $this->add('clock', CheckStatus::Failed, sprintf('This clock is behind: the repository has an entry dated %s, %s in the future', gmdate('Y-m-d H:i:s', $newest) . ' UTC', self::duration($ahead)), "Synchronise the clock (timedatectl set-ntp true, or chrony/ntpd): retention and 'latest' depend on it");

            return;
        }
        $this->add('clock', CheckStatus::Ok, 'no sign of skew (nothing in the repository is dated in the future)');
    }

    private function selinux(): void
    {
        $enforcing = is_readable($this->selinuxEnforce) && trim((string) @file_get_contents($this->selinuxEnforce)) === '1';
        if (!$enforcing) {
            $this->add('SELinux', CheckStatus::Skipped, 'not enforcing');

            return;
        }
        $this->add('SELinux', CheckStatus::Info, 'enforcing: files restored outside their original paths get the default context of the target', 'After moving restored files into place, run: restorecon -R <path>');
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => intdiv($seconds, 60) . ' min',
            $seconds < 2 * 86400 => intdiv($seconds, 3600) . ' h',
            default => intdiv($seconds, 86400) . ' d',
        };
    }
}
