<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Dump\DumpStaging;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\Restic\BackupSummary;
use Mfonte\Arkhive\Services\Restic\Node;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restic\ResticJson;
use Mfonte\Arkhive\Services\Restic\RestoreProgress;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\System\Lock;
use Mfonte\Arkhive\Support\Bytes;
use Mfonte\Arkhive\Support\Clock;

/**
 * Carries out a RestorePlan (spec §10): pre-flight (target, free space), then `restic restore --verify`
 * into the target, `restic dump` to stdout, or an import into a new database. An in-place restore holds
 * the repository lock and first takes a safety snapshot (tag pre-restore) of what it will overwrite.
 * A target directory created by this run is removed again if the restore fails or is interrupted.
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
    ) {
    }

    /**
     * Checks the target and the free space; returns the bytes the restore will write.
     *
     * @throws \Mfonte\Arkhive\Exceptions\RefusedException exit 4
     */
    public function preflight(Restic $restic, RestorePlan $plan, ?Config $config): int
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
    public function run(Restic $restic, RestorePlan $plan, ?Config $config, string $host, Logger $log, $stdout): RestoreReport
    {
        $started = hrtime(true);

        return match (true) {
            $plan->selection->kind === SelectionKind::Stdout => $this->toStdout($restic, $plan, $log, $stdout, $started),
            $plan->selection->imports() => $this->import($restic, $plan, $config, $log, $started),
            default => $this->restore($restic, $plan, $config, $host, $log, $started),
        };
    }

    private function restore(Restic $restic, RestorePlan $plan, ?Config $config, string $host, Logger $log, int $started): RestoreReport
    {
        assert($plan->target !== null);
        $safety = null;
        if ($plan->inPlace) {
            $repository = $restic->settings->repository;
            $lock = Lock::forRepository($repository, $this->environment, $this->clock, $this->uid);
            $stale = $lock->acquire('restore --in-place');
            $this->cleanup->add('release the lock', $lock->release(...));
            if ($stale !== null) {
                $log->warning($stale);
            }
            $safety = $this->safetySnapshot($restic, $plan, $host, $log);
        }

        $target = new TargetDirectory($plan->target);
        $removeTarget = null;
        if (!$plan->inPlace && !$target->exists()) {
            $removeTarget = $this->cleanup->add('remove the partly restored target', static function () use ($target): void {
                TargetDirectory::removeTree($target->path);
            });
        }

        $selection = $plan->selection;
        $args = ['restore', '--json', '--verify'];
        if ($restic->supportsSparseRestore()) {
            // runs of zeros become holes again: sparse files (images, databases) keep their real size on disk
            $args[] = '--sparse';
        }
        array_push($args, '--target', $plan->target);
        foreach ($selection->includes as $include) {
            array_push($args, '--include', $include);
        }
        foreach ($selection->excludes as $exclude) {
            array_push($args, '--exclude', $exclude);
        }
        array_push($args, '--', $plan->snapshot->id);

        $log->info(sprintf('Restoring %s from snapshot %s (%s) to %s', $selection->describe(), $plan->snapshot->shortId(), $plan->snapshot->time->format('Y-m-d H:i'), $plan->target));
        $summary = null;
        try {
            $restic->run(
                $args,
                'restic restore',
                $log->context()->interactive ? ['RESTIC_PROGRESS_FPS' => '2'] : [],
                static function (string $line) use (&$summary, $log): void {
                    $data = ResticJson::decode($line);
                    if ($data === null) {
                        return;
                    }
                    $type = ResticJson::string($data, 'message_type');
                    if ($type === 'status') {
                        $log->progress(RestoreProgress::fromJson($data)->describe());
                    } elseif ($type === 'summary') {
                        $summary = RestoreProgress::fromJson($data);
                    }
                },
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
    private function toStdout(Restic $restic, RestorePlan $plan, Logger $log, $stdout, int $started): RestoreReport
    {
        $node = $this->stdoutNode($restic, $plan);
        $log->info(sprintf('Writing %s (%s) from snapshot %s to standard output', $node->path, Bytes::format($node->size), $plan->snapshot->shortId()));
        $restic->dump($plan->snapshot->id, $node->path, $stdout);
        fflush($stdout);

        return new RestoreReport($plan, 1, $node->size, (hrtime(true) - $started) / 1e9, false, []);
    }

    private function import(Restic $restic, RestorePlan $plan, ?Config $config, Logger $log, int $started): RestoreReport
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

            $directory = $this->privateDirectory($this->importDirectory($config));
            $this->cleanup->add('remove the restored dump file', static function () use ($directory): void {
                TargetDirectory::removeTree($directory);
            });
            $file = $directory . '/' . basename($dump->path);
            $handle = @fopen($file, 'xb');
            if ($handle === false) {
                throw new ArkhiveException("Cannot create {$file} for the dump to import");
            }
            try {
                $log->info(sprintf('Restoring the %s dump of %s (%s) from snapshot %s', $dump->engineLabel(), $dump->database, Bytes::format($dump->size), $plan->snapshot->shortId()));
                $restic->dump($plan->snapshot->id, $dump->path, $handle);
            } finally {
                fclose($handle);
            }
            $restored = (int) filesize($file);
            if ($restored !== $dump->size) {
                throw new ArkhiveException(sprintf('The restored dump %s has %d bytes, the snapshot records %d', $dump->path, $restored, $dump->size));
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
    private function safetySnapshot(Restic $restic, RestorePlan $plan, string $host, Logger $log): ?string
    {
        $paths = array_values(array_filter($this->roots($plan), static fn (string $p): bool => file_exists($p) || is_link($p)));
        if ($paths === []) {
            $log->info('Nothing exists at the original paths yet: no safety snapshot is needed');

            return null;
        }
        $log->info('Taking a safety snapshot of ' . implode(' ', $paths) . ' before restoring in place');
        $summary = null;
        $restic->run(
            ['backup', '--json', '--tag', Snapshots::SAFETY_TAG, '--host', $host, '--', ...$paths],
            'The safety snapshot before the in-place restore (nothing was restored)',
            onStdoutLine: static function (string $line) use (&$summary): void {
                $data = ResticJson::decode($line);
                if ($data !== null && ResticJson::string($data, 'message_type') === 'summary') {
                    $summary = BackupSummary::fromJson($data);
                }
            },
        );
        if (!$summary instanceof BackupSummary || $summary->snapshotId === '') {
            throw new ArkhiveException('The safety snapshot reported no snapshot id: nothing was restored');
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
        $includes = $plan->selection->includes;
        if ($includes !== [] && array_filter($includes, static fn (string $i): bool => !str_starts_with($i, '/') || strpbrk($i, '*?[') !== false) === []) {
            return array_values(array_map(static fn (string $i): string => rtrim($i, '/') === '' ? '/' : rtrim($i, '/'), $includes));
        }

        return $plan->snapshot->paths;
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

    private function stdoutNode(Restic $restic, RestorePlan $plan): Node
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
            throw new UsageException("{$file} is not in snapshot {$plan->snapshot->shortId()}", "Find it with: arkhive find '" . basename($file) . "'");
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

    private function privateDirectory(string $parent): string
    {
        for ($i = 0; $i < 10; $i++) {
            $dir = rtrim($parent, '/') . '/arkhive-import-' . bin2hex(random_bytes(6));
            if (@mkdir($dir, 0700)) {
                return $dir;
            }
        }

        throw new ArkhiveException("Cannot create a private directory in {$parent} for the dump to import");
    }
}
