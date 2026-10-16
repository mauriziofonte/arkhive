<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Application\Process\Timeouts;
use Mfonte\Arkhive\Application\Resilience\StaleLocks;
use Mfonte\Arkhive\Application\System\DiskSpace;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Exclusion\ResticPatternMatcher;
use Mfonte\Arkhive\Domain\Repository\BackupOutcome;
use Mfonte\Arkhive\Domain\Repository\BackupRequest;
use Mfonte\Arkhive\Domain\Repository\BackupSummary;
use Mfonte\Arkhive\Domain\Repository\CheckResult;
use Mfonte\Arkhive\Domain\Repository\ForgetResult;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Domain\Repository\ReadDataSubset;
use Mfonte\Arkhive\Domain\Repository\RepositoryLock;
use Mfonte\Arkhive\Domain\Repository\RepositoryStats;
use Mfonte\Arkhive\Domain\Repository\ResticCapabilities;
use Mfonte\Arkhive\Domain\Repository\RestoreProgress;
use Mfonte\Arkhive\Domain\Repository\RestoreRequest;
use Mfonte\Arkhive\Domain\Repository\SftpCommand;
use Mfonte\Arkhive\Domain\Repository\SftpLocation;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\LockedException;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * The restic client. The repository and the password file go in the environment; inherited
 * password variables are removed so that only RESTIC_PASSWORD_FILE is ever used.
 */
final class Restic implements SnapshotStore
{
    /** restic backup exit code: snapshot saved, but some source files could not be read */
    public const INCOMPLETE = 3;

    /** the restic of Debian 11 (docs/2.1-PLAN.md §3) */
    public const MINIMUM_VERSION = ResticCapabilities::MINIMUM;

    /** keepalives so that a server that stops answering fails the run instead of hanging it (SftpCommand::OPTIONS) */
    public const SFTP_ARGS = '-o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes';

    /** seconds allowed for the reachability check and the other metadata reads */
    public const REACH_TIMEOUT = Timeouts::REPOSITORY;

    private ?string $version = null;

    public function __construct(
        public readonly ResticSettings $settings,
        public readonly string $binary,
        private readonly ProcessRunner $runner,
        private readonly ?string $cacheDirectory = null,
        private readonly ?StaleLocks $staleLocks = null,
    ) {
    }

    public function settings(): ResticSettings
    {
        return $this->settings;
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * @param list<string>               $args
     * @param array<string, string|null> $env
     */
    public function command(array $args, array $env = []): Command
    {
        $options = [];
        if ($this->settings->sftpCommand !== null) {
            $options = ['--option', 'sftp.command=' . SftpCommand::withKeepalives($this->settings->sftpCommand)];
        } elseif (str_starts_with($this->settings->repository, 'sftp:')) {
            $options = $this->sftpOptions();
        }

        return new Command([$this->binary, ...$options, ...$args], [
            'RESTIC_REPOSITORY' => $this->settings->repository,
            'RESTIC_PASSWORD_FILE' => $this->settings->passwordFile,
            'RESTIC_PASSWORD' => null,
            'RESTIC_PASSWORD_COMMAND' => null,
            'RESTIC_REPOSITORY_FILE' => null,
            ...($this->cacheDirectory === null ? [] : ['RESTIC_CACHE_DIR' => $this->cacheDirectory]),
            ...$env,
        ]);
    }

    /**
     * Runs restic and throws the classified error when it fails.
     *
     * @param list<string>                  $args
     * @param array<string, string|null>    $env
     * @param (callable(string): void)|null $onStdoutLine
     * @param list<int>                     $acceptedExitCodes
     * @param resource|null                 $output            receives stdout as it arrives (restic dump)
     * @param list<string>                  $writes            local paths written besides the repository and the cache (a restore target)
     */
    public function run(array $args, string $what, array $env = [], ?callable $onStdoutLine = null, array $acceptedExitCodes = [0], ?float $timeout = null, ?callable $onStderrLine = null, $output = null, array $writes = []): ProcessResult
    {
        $command = $this->command($args, $env);
        if ($timeout !== null) {
            $command = $command->withTimeout($timeout);
        }
        if ($output !== null) {
            $command = $command->withOutput($output);
        }
        $result = $this->runner->run($command, $onStdoutLine, $onStderrLine);
        if (!in_array($result->exitCode, $acceptedExitCodes, true) || $result->timedOut) {
            throw $this->failure($result, $what, $writes);
        }

        return $result;
    }

    /**
     * The classified error of a failed run: a full disk names the local paths that may be full, a
     * lock left by a dead process on this host is told apart from one held by a live run.
     *
     * @param list<string> $writes
     */
    private function failure(ProcessResult $result, string $what, array $writes): ArkhiveException
    {
        $failure = ResticErrors::exception($result, $what, $this->settings);
        if ($failure instanceof IoException) {
            return DiskSpace::annotate($failure, $this->localPaths($writes));
        }
        if ($failure instanceof LockedException && $this->staleLocks !== null) {
            return $this->staleLocks->examine($failure, ResticErrors::text($result->stderr), $this->settings, $this->locks(...), $this->unlock(...)) ?? $failure;
        }

        return $failure;
    }

    /**
     * `restic unlock`: removes the locks restic itself finds stale (older than 30 minutes, or made on
     * this host by a process that no longer exists), never the others.
     */
    private function unlock(): void
    {
        $this->run(['unlock'], 'Removing the stale lock', timeout: self::REACH_TIMEOUT);
    }

    /**
     * The local directories a failed write may have filled: a local repository, the cache, $writes.
     *
     * @param list<string> $writes
     *
     * @return list<string>
     */
    private function localPaths(array $writes): array
    {
        return array_values(array_filter([...$writes, $this->settings->localPath(), $this->cacheDirectory], static fn (?string $path): bool => $path !== null));
    }

    /**
     * "0.19.1" from `restic version`.
     */
    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }
        $result = $this->runner->run((new Command([$this->binary, 'version']))->withTimeout(Timeouts::VERSION));
        $version = self::parseVersion($result->stdout);
        if (!$result->succeeded() || $version === null) {
            throw new UnavailableException($result->failure("Running {$this->binary} version"));
        }

        return $this->version = $version;
    }

    public function capabilities(): ResticCapabilities
    {
        return ResticCapabilities::of($this->version());
    }

    public static function parseVersion(string $output): ?string
    {
        return preg_match('/^restic (\d+\.\d+\.\d+)/m', $output, $match) === 1 ? $match[1] : null;
    }

    /**
     * @throws ArkhiveException when restic is older than ResticCapabilities::MINIMUM
     */
    public function requireSupportedVersion(): string
    {
        $version = $this->version();
        if (!$this->capabilities()->supported()) {
            throw new UnavailableException(
                "restic {$version} is too old: ArkHive needs restic " . self::MINIMUM_VERSION . ' or newer',
                hint: 'Install a newer restic from your distribution, or the static binary from https://github.com/restic/restic/releases (then set RESTIC_BINARY)',
            );
        }

        return $version;
    }

    /**
     * Repository reachable and password valid (`restic cat config` reads and decrypts the config).
     */
    public function checkReachable(): void
    {
        $this->run(['cat', 'config'], 'Opening the restic repository', timeout: self::REACH_TIMEOUT);
    }

    /**
     * @return string|null the new repository id ('' when restic did not print one), null when a
     *                     repository already exists at that location (it is left untouched)
     */
    public function init(): ?string
    {
        $result = $this->run(['init', '--json'], 'Creating the restic repository', acceptedExitCodes: [0, 1], timeout: self::REACH_TIMEOUT);
        if ($result->exitCode !== 0) {
            if (str_contains($result->stdout . $result->stderr, 'config file already exists')) {
                return null;
            }

            throw ResticErrors::exception($result, 'Creating the restic repository', $this->settings);
        }
        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            $data = ResticJson::decode($line);
            if ($data !== null && ResticJson::string($data, 'id') !== '') {
                return ResticJson::string($data, 'id');
            }
        }

        // before 0.15 init prints text only: "created restic repository 8e5f9aadd7 at /srv/restic-repo"
        return preg_match('/^created restic repository ([0-9a-f]+) at /m', $result->stdout, $match) === 1 ? $match[1] : '';
    }

    /**
     * Snapshots, newest first.
     *
     * @param list<string> $paths only snapshots that include all these paths
     * @param list<string> $tags  only snapshots carrying these tags
     *
     * @return list<Snapshot>
     */
    public function snapshots(?string $host = null, array $paths = [], array $tags = []): array
    {
        $args = ['snapshots', '--json'];
        if ($host !== null) {
            array_push($args, '--host', $host);
        }
        foreach ($paths as $path) {
            array_push($args, '--path', $path);
        }
        foreach ($tags as $tag) {
            array_push($args, '--tag', $tag);
        }
        $result = $this->run($args, 'Listing the snapshots', timeout: self::REACH_TIMEOUT);
        $data = json_decode(trim($result->stdout), true);
        if (!is_array($data)) {
            throw new SoftwareException('restic snapshots returned something that is not a JSON list: ' . substr(trim($result->stdout), 0, 200));
        }
        $snapshots = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $snapshots[] = ResticOutput::snapshot($item);
            }
        }
        usort($snapshots, static fn (Snapshot $a, Snapshot $b): int => [$b->time, $b->id] <=> [$a->time, $a->id]);

        return $snapshots;
    }

    /**
     * Streams the entries of a snapshot: the directories given (their direct children, or everything
     * below them with $recursive), or the whole snapshot when none is given.
     *
     * @param list<string>         $directories absolute paths inside the snapshot
     * @param callable(Node): void $onNode
     */
    public function ls(string $snapshotId, array $directories, bool $recursive, callable $onNode): void
    {
        $args = ['ls', '--json'];
        if ($recursive) {
            $args[] = '--recursive';
        }
        array_push($args, '--', $snapshotId, ...$directories);
        $this->run($args, "Listing snapshot {$snapshotId}", onStdoutLine: static function (string $line) use ($onNode): void {
            $data = ResticJson::decode($line);
            if ($data !== null && ($data['struct_type'] ?? '') === 'node') {
                $onNode(ResticOutput::node($data));
            }
        });
    }

    /**
     * Which snapshots contain entries whose name matches $pattern.
     *
     * @param list<string> $snapshotIds only these snapshots (all when empty)
     *
     * @return list<array{string, Node}> [snapshot id, entry]
     */
    public function find(string $pattern, ?string $host = null, array $snapshotIds = []): array
    {
        $args = ['find', '--json'];
        if ($host !== null) {
            array_push($args, '--host', $host);
        }
        foreach ($snapshotIds as $id) {
            array_push($args, '--snapshot', $id);
        }
        array_push($args, '--', $pattern);
        $result = $this->run($args, "Searching the snapshots for {$pattern}");
        $data = json_decode(trim($result->stdout), true);
        if (trim($result->stdout) === '' || $data === null) {
            return [];
        }
        if (!is_array($data)) {
            throw new SoftwareException('restic find returned something that is not a JSON list: ' . substr(trim($result->stdout), 0, 200));
        }
        $found = [];
        foreach ($data as $group) {
            if (!is_array($group) || !is_string($group['snapshot'] ?? null) || !is_array($group['matches'] ?? null)) {
                continue;
            }
            foreach ($group['matches'] as $match) {
                if (is_array($match)) {
                    /** @var array<string, mixed> $match */
                    $found[] = [$group['snapshot'], ResticOutput::node($match)];
                }
            }
        }

        return $found;
    }

    /**
     * Bytes a full restore of the snapshot writes (`restic stats --mode restore-size`).
     */
    public function restoreSize(string $snapshotId): int
    {
        $result = $this->run(['stats', '--json', '--mode', 'restore-size', '--', $snapshotId], "Measuring snapshot {$snapshotId}");
        $data = ResticJson::decode($result->stdout);
        if ($data === null || !array_key_exists('total_size', $data)) {
            throw new SoftwareException('restic stats did not report a size: ' . substr(trim($result->stdout), 0, 200));
        }

        return ResticJson::int($data, 'total_size');
    }

    /**
     * The repository's lock files (read without taking a lock).
     *
     * @return list<RepositoryLock>
     */
    public function locks(): array
    {
        $result = $this->run(['list', 'locks', '--no-lock'], 'Listing the repository locks', timeout: self::REACH_TIMEOUT);
        $locks = [];
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $id) {
            $id = trim($id);
            if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
                continue;
            }
            $lock = $this->runner->run($this->command(['cat', 'lock', '--no-lock', '--', $id]));
            $data = json_decode($lock->stdout, true);
            if ($lock->succeeded() && is_array($data)) {
                /** @var array<string, mixed> $data */
                $locks[] = ResticOutput::repositoryLock($id, $data);
            }
            // a lock removed between list and cat is simply gone
        }

        return $locks;
    }

    /**
     * Writes one file of a snapshot to $output (`restic dump`).
     *
     * @param resource $output
     */
    public function dump(string $snapshotId, string $path, $output): void
    {
        $meta = stream_get_meta_data($output);
        // a file on this host: its filesystem is the one a "no space left" concerns
        $file = $meta['wrapper_type'] === 'plainfile' ? ($meta['uri'] ?? null) : null;
        $this->run(['dump', '--', $snapshotId, $path], "Reading {$path} from snapshot {$snapshotId}", output: $output, writes: $file === null ? [] : [$file]);
    }

    public function backup(BackupRequest $request, ?callable $onStatus = null): BackupOutcome
    {
        $args = ['backup', '--json'];
        foreach ($request->tags as $tag) {
            array_push($args, '--tag', $tag);
        }
        array_push($args, '--host', $request->host);
        if ($request->compression !== null && $this->capabilities()->compression()) {
            array_push($args, '--compression', $request->compression);
        }
        if ($request->excludeFile !== null) {
            array_push($args, '--exclude-file', $request->excludeFile);
        }
        array_push($args, '--', ...$request->paths);

        $summary = null;
        $unreadable = [];
        $result = $this->run(
            $args,
            $request->what,
            $request->showProgress ? ['RESTIC_PROGRESS_FPS' => '2'] : [],
            static function (string $line) use (&$summary, &$unreadable, $onStatus): void {
                $data = ResticJson::decode($line);
                if ($data === null) {
                    return;
                }
                $type = ResticJson::string($data, 'message_type');
                if ($type === 'status') {
                    if ($onStatus !== null) {
                        $onStatus(ResticOutput::backupStatus($data));
                    }
                } elseif ($type === 'summary') {
                    $summary = ResticOutput::backupSummary($data);
                } elseif ($type === 'error') {
                    $error = is_array($data['error'] ?? null) ? ResticJson::string($data['error'], 'message') : '';
                    $item = ResticJson::string($data, 'item');
                    $unreadable[] = trim(($item === '' ? '' : "{$item}: ") . $error);
                }
            },
            $request->allowIncomplete ? [0, self::INCOMPLETE] : [0],
        );

        $incomplete = $result->exitCode === self::INCOMPLETE;
        if ($incomplete) {
            // restic before 0.17 reports unreadable files on stderr only, as plain text
            foreach (preg_split('/\R/', $result->stderr) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && ResticJson::decode($line) === null) {
                    $unreadable[] = $line;
                }
            }
        }

        if ($summary instanceof BackupSummary && self::isShortId($summary->snapshotId)) {
            // before 0.16 the summary carries the short id: the full one names the snapshot unambiguously
            $summary = $summary->withSnapshotId($this->fullSnapshotId($summary->snapshotId, $request->host));
        }

        return new BackupOutcome($summary, $incomplete, array_values(array_unique($unreadable)), $result->stderrTail());
    }

    public function restore(RestoreRequest $request, ?callable $onProgress = null): ?RestoreProgress
    {
        $capabilities = $this->capabilities();
        $args = ['restore', '--json', '--verify'];
        if ($capabilities->sparseRestore()) {
            // runs of zeros become holes again: sparse files (images, databases) keep their real size on disk
            $args[] = '--sparse';
        }
        array_push($args, '--target', $request->target);
        foreach ($request->includes as $include) {
            array_push($args, '--include', $include);
        }
        foreach ($request->excludes as $exclude) {
            array_push($args, '--exclude', $exclude);
        }
        array_push($args, '--', $request->snapshotId);

        $summary = null;
        $this->run(
            $args,
            $request->what,
            $request->showProgress ? ['RESTIC_PROGRESS_FPS' => '2'] : [],
            static function (string $line) use (&$summary, $onProgress): void {
                $data = ResticJson::decode($line);
                if ($data === null) {
                    return;
                }
                $type = ResticJson::string($data, 'message_type');
                if ($type === 'status' && $onProgress !== null) {
                    $onProgress(ResticOutput::restoreProgress($data));
                } elseif ($type === 'summary') {
                    $summary = ResticOutput::restoreProgress($data);
                }
            },
            writes: [$request->target],
        );

        if ($summary === null && !$capabilities->jsonRestore()) {
            // before 0.16 restore reports nothing machine-readable: count what the selection holds
            $summary = $this->restoredTotals($request);
        }

        return $summary;
    }

    /**
     * The files, directories and bytes of the snapshot that a restore request selects, as restic's
     * own summary would count them.
     */
    private function restoredTotals(RestoreRequest $request): RestoreProgress
    {
        $count = 0;
        $bytes = 0;
        $this->ls($request->snapshotId, [], true, static function (Node $node) use (&$count, &$bytes, $request): void {
            if (ResticPatternMatcher::excludes($request->excludes, $node->path)) {
                return;
            }
            if ($request->includes !== [] && !ResticPatternMatcher::excludes($request->includes, $node->path)) {
                return;
            }
            $count++;
            if ($node->isFile()) {
                $bytes += $node->size;
            }
        });

        return new RestoreProgress(1.0, $count, $count, $bytes, $bytes);
    }

    /**
     * The sftp options: keepalives and batch mode, as `sftp.args` from restic 0.16.1, before that as a
     * whole `sftp.command` built from the repository URL (restic's own default command plus the options).
     *
     * @return list<string>
     */
    private function sftpOptions(): array
    {
        if ($this->capabilities()->sftpArgs()) {
            return ['--option', 'sftp.args=' . self::SFTP_ARGS];
        }
        $location = SftpLocation::parse($this->settings->repository);
        if ($location === null) {
            return [];
        }

        return ['--option', 'sftp.command=' . $location->sshCommand(explode(' ', self::SFTP_ARGS))];
    }

    private static function isShortId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8,63}$/', $id) === 1;
    }

    private function fullSnapshotId(string $shortId, string $host): string
    {
        foreach ($this->snapshots($host) as $snapshot) {
            if (str_starts_with($snapshot->id, $shortId)) {
                return $snapshot->id;
            }
        }

        return $shortId;
    }

    public function mount(string $directory, ?string $host = null): void
    {
        $args = ['mount'];
        if ($host !== null) {
            array_push($args, '--host', $host);
        }
        array_push($args, '--', $directory);
        $this->run($args, 'restic mount');
    }

    /**
     * Applies the retention policy to this host's ArkHive snapshots. Snapshots are grouped by host
     * only, so a change of BACKUP_PATHS does not leave the old snapshots outside the policy. No
     * --prune: forget and prune run apart, so that a prune refused on a damaged repository does not
     * hide the snapshots forget already removed.
     */
    public function forget(string $host, string $tag, int $daily, int $weekly, int $monthly): ForgetResult
    {
        $result = $this->run([
            'forget', '--json',
            '--host', $host,
            '--tag', $tag,
            '--group-by', 'host',
            '--keep-daily', (string) $daily,
            '--keep-weekly', (string) $weekly,
            '--keep-monthly', (string) $monthly,
        ], 'Applying the retention policy (restic forget)');

        return ResticOutput::forgetResult($result->stdout);
    }

    /**
     * `restic prune`: removes the data no snapshot uses any more (no timeout: it may take hours).
     */
    public function prune(): void
    {
        $this->run(['prune'], 'Pruning the data no snapshot uses (restic prune)');
    }

    /**
     * `restic check`, optionally reading back a subset of the data (5%, 1/10, 500M).
     */
    public function check(?string $readDataSubset = null): CheckResult
    {
        $args = ['check', '--json'];
        if ($readDataSubset !== null) {
            $subset = ReadDataSubset::adapt($readDataSubset, $this->capabilities(), fn (): int => $this->stats()->rawBytes, random_int(0, PHP_INT_MAX));
            $args[] = '--read-data-subset=' . $subset;
        }
        $what = $readDataSubset === null ? 'Checking the repository structure' : "Reading back {$readDataSubset} of the repository data";
        $result = $this->run($args, $what, acceptedExitCodes: [0, 1]);
        $check = ResticOutput::checkResult($result->exitCode, $result->stdout, $result->stderr);
        if ($result->exitCode !== 0 && $check->errors === []) {
            // exit 1 without anything recognisable: report restic's own words
            return new CheckResult(false, [$result->stderrTail() === '' ? 'restic check failed without a message' : $result->stderrTail()], []);
        }

        return $check;
    }

    /**
     * Size of the stored data after deduplication and compression (read without a lock).
     */
    public function stats(): RepositoryStats
    {
        $result = $this->run(['stats', '--json', '--no-lock', '--mode', 'raw-data'], 'Measuring the repository', timeout: self::REACH_TIMEOUT);
        $data = ResticJson::decode($result->stdout);
        if ($data === null || !array_key_exists('total_size', $data)) {
            throw new SoftwareException('restic stats did not report a size: ' . substr(trim($result->stdout), 0, 200));
        }

        return ResticOutput::repositoryStats($data);
    }
}
