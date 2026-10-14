<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\BackupOutcome;
use Mfonte\Arkhive\Domain\Repository\BackupRequest;
use Mfonte\Arkhive\Domain\Repository\BackupStatus;
use Mfonte\Arkhive\Domain\Repository\CheckResult;
use Mfonte\Arkhive\Domain\Repository\ForgetResult;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Domain\Repository\RepositoryLock;
use Mfonte\Arkhive\Domain\Repository\RepositoryStats;
use Mfonte\Arkhive\Domain\Repository\RestoreProgress;
use Mfonte\Arkhive\Domain\Repository\RestoreRequest;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * The encrypted, deduplicated snapshot repository (restic). Every failure is an ArkhiveException
 * carrying the exit code and the fix; the adapter hides which restic version is installed.
 */
interface SnapshotStore
{
    public function settings(): ResticSettings;

    /**
     * The client program, for the log ("/usr/bin/restic").
     */
    public function binary(): string;

    /**
     * The client version ("0.19.1").
     */
    public function version(): string;

    /**
     * @throws ArkhiveException when the client is too old for ArkHive
     */
    public function requireSupportedVersion(): string;

    /**
     * Repository reachable and password valid.
     */
    public function checkReachable(): void;

    /**
     * @return string|null the new repository id ('' when unknown), null when a repository already
     *                     exists at that location (it is left untouched)
     */
    public function init(): ?string;

    /**
     * Snapshots, newest first.
     *
     * @param list<string> $paths only snapshots that include all these paths
     * @param list<string> $tags  only snapshots carrying these tags
     *
     * @return list<Snapshot>
     */
    public function snapshots(?string $host = null, array $paths = [], array $tags = []): array;

    /**
     * Streams the entries of a snapshot: the directories given (their direct children, or everything
     * below them with $recursive), or the whole snapshot when none is given.
     *
     * @param list<string>         $directories absolute paths inside the snapshot
     * @param callable(Node): void $onNode
     */
    public function ls(string $snapshotId, array $directories, bool $recursive, callable $onNode): void;

    /**
     * Which snapshots contain entries whose name matches $pattern.
     *
     * @param list<string> $snapshotIds only these snapshots (all when empty)
     *
     * @return list<array{string, Node}> [snapshot id, entry]
     */
    public function find(string $pattern, ?string $host = null, array $snapshotIds = []): array;

    /**
     * Bytes a full restore of the snapshot writes.
     */
    public function restoreSize(string $snapshotId): int;

    /**
     * The repository's lock files, read without taking a lock.
     *
     * @return list<RepositoryLock>
     */
    public function locks(): array;

    /**
     * Writes one file of a snapshot to $output.
     *
     * @param resource $output
     */
    public function dump(string $snapshotId, string $path, $output): void;

    /**
     * Takes a snapshot. $onStatus receives the progress reports while it runs.
     *
     * @param (callable(BackupStatus): void)|null $onStatus
     */
    public function backup(BackupRequest $request, ?callable $onStatus = null): BackupOutcome;

    /**
     * Restores and verifies files of a snapshot; returns the final totals when the client reports them.
     *
     * @param (callable(RestoreProgress): void)|null $onProgress
     */
    public function restore(RestoreRequest $request, ?callable $onProgress = null): ?RestoreProgress;

    /**
     * Mounts the repository read-only on $directory until interrupted.
     */
    public function mount(string $directory, ?string $host = null): void;

    /**
     * Applies the retention policy to this host's snapshots carrying $tag and prunes unused data.
     */
    public function forget(string $host, string $tag, int $daily, int $weekly, int $monthly): ForgetResult;

    /**
     * Checks the repository structure, optionally reading back a subset of the data (5%, 1/10, 500M).
     */
    public function check(?string $readDataSubset = null): CheckResult;

    /**
     * Size of the stored data after deduplication and compression, read without a lock.
     */
    public function stats(): RepositoryStats;
}
