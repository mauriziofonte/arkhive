<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Services\Config\ResticSettings;
use Mfonte\Arkhive\Services\Process\Command;
use Mfonte\Arkhive\Services\Process\ProcessResult;
use Mfonte\Arkhive\Services\Process\ProcessRunner;

/**
 * The restic client. The repository and the password file go in the environment; inherited
 * password variables are removed so that only RESTIC_PASSWORD_FILE is ever used.
 */
final class Restic
{
    /** spec §4: repository v2 and compression */
    public const MINIMUM_VERSION = '0.14.0';

    /** keepalives so that a server that stops answering fails the run instead of hanging it */
    public const SFTP_ARGS = '-o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes';

    /** seconds allowed for the reachability check */
    public const REACH_TIMEOUT = 300.0;

    /** `restore --sparse` (zero runs written as holes); assumed from 0.15 to stay on the safe side */
    public const SPARSE_RESTORE_VERSION = '0.15.0';

    private ?string $version = null;

    public function __construct(
        public readonly ResticSettings $settings,
        public readonly string $binary,
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * @param list<string>               $args
     * @param array<string, string|null> $env
     */
    public function command(array $args, array $env = []): Command
    {
        $options = [];
        if ($this->settings->sftpCommand !== null) {
            $options = ['--option', 'sftp.command=' . $this->settings->sftpCommand];
        } elseif (str_starts_with($this->settings->repository, 'sftp:')) {
            $options = ['--option', 'sftp.args=' . self::SFTP_ARGS];
        }

        return new Command([$this->binary, ...$options, ...$args], [
            'RESTIC_REPOSITORY' => $this->settings->repository,
            'RESTIC_PASSWORD_FILE' => $this->settings->passwordFile,
            'RESTIC_PASSWORD' => null,
            'RESTIC_PASSWORD_COMMAND' => null,
            'RESTIC_REPOSITORY_FILE' => null,
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
     */
    public function run(array $args, string $what, array $env = [], ?callable $onStdoutLine = null, array $acceptedExitCodes = [0], ?float $timeout = null, ?callable $onStderrLine = null, $output = null): ProcessResult
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
            throw ResticErrors::exception($result, $what, $this->settings);
        }

        return $result;
    }

    /**
     * "0.19.1" from `restic version`.
     */
    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }
        $result = $this->runner->run(new Command([$this->binary, 'version']));
        $version = self::parseVersion($result->stdout);
        if (!$result->succeeded() || $version === null) {
            throw new UnavailableException($result->failure("Running {$this->binary} version"));
        }

        return $this->version = $version;
    }

    public function supportsSparseRestore(): bool
    {
        return version_compare($this->version(), self::SPARSE_RESTORE_VERSION, '>=');
    }

    public static function parseVersion(string $output): ?string
    {
        return preg_match('/^restic (\d+\.\d+\.\d+)/m', $output, $match) === 1 ? $match[1] : null;
    }

    /**
     * @throws ArkhiveException when restic is older than 0.14
     */
    public function requireSupportedVersion(): string
    {
        $version = $this->version();
        if (version_compare($version, self::MINIMUM_VERSION, '<')) {
            throw new UnavailableException(
                "restic {$version} is too old: ArkHive needs restic " . self::MINIMUM_VERSION . ' or newer (repository format 2, compression)',
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
        $result = $this->run(['init', '--json'], 'Creating the restic repository', acceptedExitCodes: [0, 1]);
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

        return '';
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
                $snapshots[] = Snapshot::fromJson($item);
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
                $onNode(Node::fromJson($data));
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
                    $found[] = [$group['snapshot'], Node::fromJson($match)];
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
                $locks[] = RepositoryLock::fromJson($id, $data);
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
        $this->run(['dump', '--', $snapshotId, $path], "Reading {$path} from snapshot {$snapshotId}", output: $output);
    }

    /**
     * Applies the retention policy to this host's ArkHive snapshots and prunes the data no snapshot
     * uses any more. Snapshots are grouped by host only, so a change of BACKUP_PATHS does not leave
     * the old snapshots outside the policy.
     */
    public function forget(string $host, string $tag, int $daily, int $weekly, int $monthly): ForgetResult
    {
        $result = $this->run([
            'forget', '--json', '--prune',
            '--host', $host,
            '--tag', $tag,
            '--group-by', 'host',
            '--keep-daily', (string) $daily,
            '--keep-weekly', (string) $weekly,
            '--keep-monthly', (string) $monthly,
        ], 'Applying the retention policy (restic forget --prune)');

        return ForgetResult::fromJson($result->stdout);
    }

    /**
     * `restic check`, optionally reading back a subset of the data (5%, 1/10, 500M).
     */
    public function check(?string $readDataSubset = null): CheckResult
    {
        $args = ['check', '--json'];
        if ($readDataSubset !== null) {
            $args[] = '--read-data-subset=' . $readDataSubset;
        }
        $what = $readDataSubset === null ? 'Checking the repository structure' : "Reading back {$readDataSubset} of the repository data";
        $result = $this->run($args, $what, acceptedExitCodes: [0, 1]);
        $check = CheckResult::fromOutput($result->exitCode, $result->stdout, $result->stderr);
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

        return RepositoryStats::fromJson($data);
    }
}
