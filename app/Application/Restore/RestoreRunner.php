<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Restore;

use Mfonte\Arkhive\Application\Database\DatabaseImporter;
use Mfonte\Arkhive\Application\Database\DumpStaging;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Domain\Repository\BackupRequest;
use Mfonte\Arkhive\Domain\Repository\BackupSummary;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Domain\Repository\RestoreProgress;
use Mfonte\Arkhive\Domain\Repository\RestoreRequest;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Restore\OwnerMode;
use Mfonte\Arkhive\Domain\Restore\RestorePlan;
use Mfonte\Arkhive\Domain\Restore\RestoreReport;
use Mfonte\Arkhive\Domain\Restore\SelectionKind;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * Carries out a RestorePlan (spec §10): pre-flight (target, free space), then `restic restore --verify`
 * into the target, `restic dump` to stdout, or an import into a new database. An in-place restore holds
 * the repository lock and first takes a safety snapshot (tag pre-restore) of what it will overwrite.
 * A target directory created by this run is removed again if the restore fails or is interrupted.
 * A repository locked by another run is waited for, up to LOCK_WAIT.
 */
final class RestoreRunner
{
    /**
     * @param array<string, string> $environment for the lock directory
     */
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
        private readonly Clock $clock,
        private readonly array $environment,
        private readonly int $uid,
        private readonly int $gid,
        private readonly Retrier $retrier,
    ) {
    }

    /**
     * Checks the target and the free space; returns the bytes the restore will write.
     *
     * @throws \Mfonte\Arkhive\Exceptions\RefusedException exit 73
     */
    public function preflight(SnapshotStore $restic, RestorePlan $plan, ?Config $config): int
    {
        $selection = $plan->selection;
        if ($selection->kind === SelectionKind::Stdout) {
            return $this->stdoutNode($restic, $plan)->size;
        }
        $needed = SizeEstimator::estimate($restic, $plan);
        if ($selection->imports()) {
            (new TargetDirectory($this->importDirectory($config)))->assertSpace($needed, 'the dump to import');

            return $needed;
        }
        assert($plan->target !== null);
        $target = new TargetDirectory($plan->target);
        if ($plan->inPlace) {
            $overwritten = 0;
            foreach ($this->roots($plan) as $root) {
                $overwritten += TargetDirectory::usedBytes($root);
            }
            $target->assertSpace(max(0, $needed - $overwritten), 'the in-place restore');

            return $needed;
        }
        $target->assertUsable($plan->force);
        $target->assertSpace($needed);

        return $needed;
    }

    /**
     * @param resource $stdout where --to-stdout writes the file
     */
    public function run(SnapshotStore $restic, RestorePlan $plan, ?Config $config, string $host, Logger $log, $stdout): RestoreReport
    {
        $started = hrtime(true);

        return match (true) {
            $plan->selection->kind === SelectionKind::Stdout => $this->toStdout($restic, $plan, $log, $stdout, $started),
            $plan->selection->imports() => $this->import($restic, $plan, $config, $log, $started),
            default => $this->restore($restic, $plan, $config, $host, $log, $started),
        };
    }

    private function restore(SnapshotStore $restic, RestorePlan $plan, ?Config $config, string $host, Logger $log, int $started): RestoreReport
    {
        assert($plan->target !== null);
        $safety = null;
        $lockPolicy = RetryPolicy::lock($restic->settings()->lockWait);
        if ($plan->inPlace) {
            $repository = $restic->settings()->repository;
            $lock = Lock::forRepository($repository, $this->environment, $this->clock, $this->uid);
            $stale = $this->retrier->whileLocked(static fn (): ?string => $lock->acquire('restore --in-place'), $lockPolicy, 'Waiting for the repository', $log);
            $this->cleanup->add('release the lock', $lock->release(...));
            if ($stale !== null) {
                $log->warning($stale);
            }
            $safety = $this->safetySnapshot($restic, $plan, $host, $log, $lockPolicy);
        }

        $target = new TargetDirectory($plan->target);
        $removeTarget = null;
        if (!$plan->inPlace && !$target->exists()) {
            $removeTarget = $this->cleanup->add('remove the partly restored target', static function () use ($target): void {
                TargetDirectory::removeTree($target->path);
            });
        }

        $selection = $plan->selection;
        $request = new RestoreRequest(
            $plan->snapshot->id,
            $plan->target,
            'restic restore',
            $selection->includes,
            $selection->excludes,
            $log->context()->interactive,
        );

        $log->info(sprintf('Restoring %s from snapshot %s (%s) to %s', $selection->describe(), $plan->snapshot->shortId(), $plan->snapshot->time->format('Y-m-d H:i'), $plan->target));
        try {
            $summary = $this->retrier->whileLocked(
                static fn () => $restic->restore($request, static function (RestoreProgress $progress) use ($log): void {
                    $log->progress($progress->describe());
                }),
                $lockPolicy,
                'restic restore',
                $log,
            );
        } finally {
            $log->endProgress();
        }
        if ($removeTarget !== null) {
            $this->cleanup->forget($removeTarget);
        }

        $warnings = $this->applyOwner($plan, $log);
        $files = $summary instanceof RestoreProgress ? $summary->filesRestored : 0;
        $bytes = $summary instanceof RestoreProgress ? $summary->bytesRestored : 0;
        $log->success(sprintf('Restored and verified %d files and directories (%s) in %s', $files, Bytes::format($bytes), $plan->target), ['files' => $files, 'bytes' => $bytes]);

        return new RestoreReport($plan, $files, $bytes, (hrtime(true) - $started) / 1e9, true, $this->nextSteps($plan, $config, $safety), $safety, $warnings);
    }

    /**
     * @param resource $stdout
     */
    private function toStdout(SnapshotStore $restic, RestorePlan $plan, Logger $log, $stdout, int $started): RestoreReport
    {
        $node = $this->stdoutNode($restic, $plan);
        $log->info(sprintf('Writing %s (%s) from snapshot %s to standard output', $node->path, Bytes::format($node->size), $plan->snapshot->shortId()));
        $restic->dump($plan->snapshot->id, $node->path, $stdout);
        fflush($stdout);

        return new RestoreReport($plan, 1, $node->size, (hrtime(true) - $started) / 1e9, false, []);
    }

    private function import(SnapshotStore $restic, RestorePlan $plan, ?Config $config, Logger $log, int $started): RestoreReport
    {
        $selection = $plan->selection;
        $dump = $selection->dump;
        $name = $selection->importAs;
        assert($dump !== null && $name !== null);
        if ($config === null) {
            throw new UsageException('--import-as needs the database connection settings of a configuration file', 'Pass --config, or restore the dump file without --import-as');
        }
        $importer = new DatabaseImporter($config, $dump->engine, $this->runner, $this->dependencies, $this->cleanup);
        try {
            $created = $importer->prepare($name, $plan->force);
            $dropId = null;
            if ($created) {
                $dropId = $this->cleanup->add("drop the partly imported database {$name}", static function () use ($importer, $name): void {
                    $importer->drop($name);
                });
            }

            $directory = TargetDirectory::createPrivate($this->importDirectory($config), 'arkhive-import-', 'the dump to import');
            $this->cleanup->add('remove the restored dump file', static function () use ($directory): void {
                TargetDirectory::removeTree($directory);
            });
            $file = $directory . '/' . basename($dump->path);
            $handle = @fopen($file, 'xb');
            if ($handle === false) {
                throw new RefusedException("Cannot create {$file} for the dump to import");
            }
            try {
                $log->info(sprintf('Restoring the %s dump of %s (%s) from snapshot %s', $dump->engineLabel(), $dump->database, Bytes::format($dump->size), $plan->snapshot->shortId()));
                $restic->dump($plan->snapshot->id, $dump->path, $handle);
            } finally {
                fclose($handle);
            }
            $restored = (int) filesize($file);
            if ($restored !== $dump->size) {
                throw new DataException(sprintf('The restored dump %s has %d bytes, the snapshot records %d', $dump->path, $restored, $dump->size));
            }

            $log->info(($created ? "Created the database {$name}; importing" : "Importing into the existing database {$name} (--force)") . " {$dump->label()}");
            $importer->import($name, $file);
            if ($dropId !== null) {
                $this->cleanup->forget($dropId);
            }
        } finally {
            $importer->close();
        }
        $log->success(sprintf('Imported %s (%s) into the database %s', $dump->label(), Bytes::format($dump->size), $name));

        return new RestoreReport($plan, 1, $dump->size, (hrtime(true) - $started) / 1e9, false, [
            "Check the data in {$name}, then point the application at it or rename it; the original database {$dump->database} was not touched",
        ]);
    }

    /**
     * The safety snapshot of the paths an in-place restore overwrites, so that the restore can be undone.
     */
    private function safetySnapshot(SnapshotStore $restic, RestorePlan $plan, string $host, Logger $log, RetryPolicy $lockPolicy): ?string
    {
        $paths = array_values(array_filter($this->roots($plan), static fn (string $p): bool => file_exists($p) || is_link($p)));
        if ($paths === []) {
            $log->info('Nothing exists at the original paths yet: no safety snapshot is needed');

            return null;
        }
        $log->info('Taking a safety snapshot of ' . implode(' ', $paths) . ' before restoring in place');
        $request = new BackupRequest(
            $paths,
            $host,
            [Snapshots::SAFETY_TAG],
            'The safety snapshot before the in-place restore (nothing was restored)',
        );
        $summary = $this->retrier->whileLocked(static fn () => $restic->backup($request), $lockPolicy, 'The safety snapshot', $log)->summary;
        if (!$summary instanceof BackupSummary || $summary->snapshotId === '') {
            throw new SoftwareException('The safety snapshot reported no snapshot id: nothing was restored');
        }
        $log->success("Safety snapshot {$summary->shortId()} saved (tag " . Snapshots::SAFETY_TAG . "); undo the restore with: arkhive restore --snapshot {$summary->shortId()} --in-place --force", ['safety_snapshot' => $summary->snapshotId]);

        return $summary->snapshotId;
    }

    /**
     * The original paths a restore writes: the included paths when they are plain paths, otherwise
     * every path of the snapshot.
     *
     * @return list<string>
     */
    private function roots(RestorePlan $plan): array
    {
        $roots = [];
        foreach ($plan->selection->includes as $include) {
            $path = ExclusionConverter::unliteral($include);
            if ($path === null || !str_starts_with($path, '/')) {
                // a wildcard pattern: the safety snapshot covers the whole snapshot paths
                return $plan->snapshot->paths;
            }
            $roots[] = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
        }

        return $roots === [] ? $plan->snapshot->paths : $roots;
    }

    /**
     * @return list<string>
     */
    private function applyOwner(RestorePlan $plan, Logger $log): array
    {
        if ($plan->owner === OwnerMode::Preserve) {
            return [];
        }
        if ($this->uid !== 0) {
            // what --owner current means for a non-root user: said, but not a failure (exit 0)
            $log->warning("Ownership was not restored: running as uid {$this->uid}, the restored files belong to you (run as root to restore the original owners)");

            return [];
        }
        $failed = 0;
        foreach ($this->restoredRoots($plan) as $root) {
            $failed += TargetDirectory::chownTree($root, $this->uid, $this->gid);
        }
        if ($failed > 0) {
            $warning = "Could not give {$failed} restored entries to uid {$this->uid}";
            $log->warning($warning);

            return [$warning];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function restoredRoots(RestorePlan $plan): array
    {
        $target = rtrim((string) $plan->target, '/');

        return array_values(array_filter(
            array_map(static fn (string $root): string => $target . $root, $this->roots($plan)),
            static fn (string $p): bool => file_exists($p) || is_link($p),
        ));
    }

    /**
     * @return list<string>
     */
    private function nextSteps(RestorePlan $plan, ?Config $config, ?string $safety): array
    {
        $short = $plan->snapshot->shortId();
        if ($plan->inPlace) {
            return $safety === null ? [] : ['Undo this restore with: arkhive restore --snapshot ' . substr($safety, 0, 8) . ' --in-place --force'];
        }
        $dump = $plan->selection->dump;
        if ($dump !== null) {
            $file = rtrim((string) $plan->target, '/') . $dump->path;

            return [
                "Import it into a new database with: arkhive restore --snapshot {$short} --db " . escapeshellarg($dump->database) . ' --import-as ' . self::suggestedName($dump->database),
                'or by hand: ' . DatabaseImporter::manualCommand($dump->engine, $file, $config),
            ];
        }
        $steps = [];
        foreach ($this->restoredRoots($plan) as $restored) {
            $original = substr($restored, strlen(rtrim((string) $plan->target, '/')));
            if (self::isDumpDirectory($restored)) {
                $steps[] = "{$original} holds database dumps: import one into a new database with: arkhive restore --snapshot {$short} --db <name> --import-as <new name>";
                continue;
            }
            $steps[] = 'Review, then put it in place with: rsync -aHAX --numeric-ids ' . escapeshellarg(is_dir($restored) ? $restored . '/' : $restored) . ' ' . escapeshellarg(is_dir($restored) ? $original . '/' : $original);
        }
        $includes = implode('', array_map(static fn (string $i): string => ' --include ' . escapeshellarg($i), $plan->selection->includes));
        $steps[] = "or restore in place (a safety snapshot is taken first): arkhive restore --snapshot {$short}{$includes} --in-place --force";

        return $steps;
    }

    /**
     * A restored DUMP_DIRECTORY: only mysql/ and pgsql/ subdirectories (never put back with rsync).
     */
    private static function isDumpDirectory(string $path): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return false;
        }
        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));

        return $entries !== [] && array_diff($entries, DumpStaging::ENGINES) === [];
    }

    private static function suggestedName(string $database): string
    {
        $base = (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $database);

        return substr($base === '' ? 'restored' : $base, 0, 54) . '_restored';
    }

    private function stdoutNode(SnapshotStore $restic, RestorePlan $plan): Node
    {
        $file = (string) $plan->selection->file;
        if (!str_starts_with($file, '/')) {
            throw new UsageException("--to-stdout needs the absolute path of the file in the snapshot, not '{$file}'");
        }
        $found = null;
        $restic->ls($plan->snapshot->id, [dirname($file)], false, static function (Node $node) use (&$found, $file): void {
            if ($node->path === $file) {
                $found = $node;
            }
        });
        if (!$found instanceof Node) {
            throw new NoInputException("{$file} is not in snapshot {$plan->snapshot->shortId()}", "Find it with: arkhive find '" . basename($file) . "'");
        }
        if (!$found->isFile()) {
            throw new UsageException("{$file} is a {$found->type}, not a file: --to-stdout writes a single file", "Restore it with: arkhive restore --snapshot {$plan->snapshot->shortId()} --include '{$file}' --target <new directory>");
        }

        return $found;
    }

    private function importDirectory(?Config $config): string
    {
        $dir = $config?->dumpDirectory;

        return $dir !== null && is_dir($dir) ? $dir : sys_get_temp_dir();
    }
}
