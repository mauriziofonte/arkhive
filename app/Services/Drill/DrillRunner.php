<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Drill;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\Restic\Node;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restic\Snapshot;
use Mfonte\Arkhive\Services\Restore\DatabaseImporter;
use Mfonte\Arkhive\Services\Restore\DumpFile;
use Mfonte\Arkhive\Services\Restore\DumpFinder;
use Mfonte\Arkhive\Services\Restore\TargetDirectory;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Support\Bytes;
use Random\Randomizer;

/**
 * spec §10.5: restores N random files of a snapshot into a private temporary directory with
 * `restic restore --verify`, checks every one of them, optionally restores one dump and imports it
 * into a throw-away database, then removes everything. Read-only on the repository: no lock taken.
 */
final class DrillRunner
{
    public const DEFAULT_SAMPLE = 50;

    public const DATABASE_PREFIX = 'arkhive_drill_';

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
        private readonly Randomizer $random,
        private readonly string $tempDir,
    ) {
    }

    /**
     * @param positive-int $sample
     */
    public function run(Restic $restic, Snapshot $snapshot, int $sample, ?Config $config, bool $withDatabase, Logger $log): DrillReport
    {
        $started = hrtime(true);
        if ($withDatabase && ($config === null || !$config->hasDumps())) {
            throw new UsageException('--db needs a configuration with WITH_MYSQL or WITH_PGSQL: the test import uses its database connection', 'Pass --config, or run the drill without --db');
        }

        $files = [];
        $restic->ls($snapshot->id, [], true, static function (Node $node) use (&$files): void {
            if ($node->isFile()) {
                $files[] = $node;
            }
        });
        if ($files === []) {
            return new DrillReport($snapshot, 0, [], 0, ["snapshot {$snapshot->shortId()} contains no file to restore"], null, self::since($started));
        }
        $picked = $this->pick($files, $sample);
        $bytes = array_sum(array_map(static fn (Node $node): int => $node->size, $picked));

        $directory = TargetDirectory::createPrivate($this->tempDir, 'arkhive-drill-', 'the restore drill');
        $removeId = $this->cleanup->add('remove the drill directory', static function () use ($directory): void {
            TargetDirectory::removeTree($directory);
        });
        try {
            return $this->drill($restic, $snapshot, $files, $picked, $bytes, $directory, $config, $withDatabase, $log, $started);
        } finally {
            $failure = $this->cleanup->runOne($removeId);
            if ($failure !== null) {
                $log->warning("Could not remove the drill directory: {$failure}");
            }
        }
    }

    /**
     * @param non-empty-list<Node> $files
     * @param list<Node>           $picked
     */
    private function drill(Restic $restic, Snapshot $snapshot, array $files, array $picked, int $bytes, string $directory, ?Config $config, bool $withDatabase, Logger $log, int $started): DrillReport
    {
        (new TargetDirectory($directory))->assertSpace($bytes, 'the restore drill');

        $log->info(sprintf(
            'Restoring %d random files of %d (%s) from snapshot %s (%s) into %s',
            count($picked),
            count($files),
            Bytes::format($bytes),
            $snapshot->shortId(),
            $snapshot->time->format('Y-m-d H:i'),
            $directory,
        ));
        $failures = $this->restore($restic, $snapshot, $picked, $directory);
        if ($failures === []) {
            $log->success(sprintf('Restored and verified %d files (%s)', count($picked), Bytes::format($bytes)));
        }
        foreach ($failures as $failure) {
            $log->error("Drill: {$failure}");
        }

        $database = null;
        if ($withDatabase) {
            assert($config !== null);
            $database = $this->database($restic, $snapshot, $config, $directory, $log);
        }

        return new DrillReport(
            $snapshot,
            count($files),
            array_map(static fn (Node $node): string => $node->path, $picked),
            $bytes,
            $failures,
            $database,
            self::since($started),
        );
    }

    /**
     * @param non-empty-list<Node> $files
     * @param positive-int         $sample
     *
     * @return list<Node> at most $sample of them, in snapshot order
     */
    private function pick(array $files, int $sample): array
    {
        if ($sample >= count($files)) {
            return $files;
        }
        $keys = $this->random->pickArrayKeys($files, $sample);

        return array_values(array_map(static fn (int $key): Node => $files[$key], $keys));
    }

    /**
     * @param list<Node> $picked
     *
     * @return list<string> failures
     */
    private function restore(Restic $restic, Snapshot $snapshot, array $picked, string $directory): array
    {
        $args = ['restore', '--json', '--verify'];
        if ($restic->supportsSparseRestore()) {
            $args[] = '--sparse';
        }
        array_push($args, '--target', $directory);
        foreach ($picked as $node) {
            array_push($args, '--include', ExclusionConverter::literal($node->path));
        }
        array_push($args, '--', $snapshot->id);
        try {
            $restic->run($args, 'restic restore (drill)');
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            return ['the restore failed: ' . $e->getMessage()];
        }

        $failures = [];
        foreach ($picked as $node) {
            $restored = $directory . $node->path;
            clearstatcache(true, $restored);
            if (is_link($restored) || !is_file($restored)) {
                $failures[] = "{$node->path} was not restored";
            } elseif (($size = (int) filesize($restored)) !== $node->size) {
                $failures[] = sprintf('%s has %d bytes after the restore, the snapshot records %d', $node->path, $size, $node->size);
            }
        }

        return $failures;
    }

    /**
     * @return array{engine: string, database: string, imported_as: string, bytes: int, ok: bool, error: ?string}
     */
    private function database(Restic $restic, Snapshot $snapshot, Config $config, string $directory, Logger $log): array
    {
        $engines = array_keys(array_filter(['mysql' => $config->mysql !== null, 'pgsql' => $config->pgsql !== null]));
        $dumps = array_values(array_filter(DumpFinder::find($restic, $snapshot), static fn (DumpFile $dump): bool => in_array($dump->engine, $engines, true)));
        if ($dumps === []) {
            $error = "snapshot {$snapshot->shortId()} has no " . implode(' or ', array_map(static fn (string $e): string => $e === 'mysql' ? 'MySQL/MariaDB' : 'PostgreSQL', $engines)) . ' dump to test';
            $log->error("Drill: {$error}");

            return ['engine' => $engines[0] ?? '', 'database' => '', 'imported_as' => '', 'bytes' => 0, 'ok' => false, 'error' => $error];
        }
        $dump = $dumps[$this->random->getInt(0, count($dumps) - 1)];
        $name = self::DATABASE_PREFIX . bin2hex($this->random->getBytes(4));
        $result = ['engine' => $dump->engine, 'database' => $dump->database, 'imported_as' => $name, 'bytes' => $dump->size, 'ok' => false, 'error' => null];

        $importer = new DatabaseImporter($config, $dump->engine, $this->runner, $this->dependencies, $this->cleanup);
        $dropId = null;
        try {
            $file = $directory . '/' . basename($dump->path);
            $handle = @fopen($file, 'xb');
            if ($handle === false) {
                throw new ArkhiveException("Cannot create {$file} for the dump to test");
            }
            try {
                $log->info(sprintf('Restoring the %s dump of %s (%s) and importing it into the throw-away database %s', $dump->engineLabel(), $dump->database, Bytes::format($dump->size), $name));
                $restic->dump($snapshot->id, $dump->path, $handle);
            } finally {
                fclose($handle);
            }
            $restored = (int) filesize($file);
            if ($restored !== $dump->size) {
                throw new ArkhiveException(sprintf('the restored dump %s has %d bytes, the snapshot records %d', $dump->path, $restored, $dump->size));
            }
            $importer->prepare($name, false);
            $dropId = $this->cleanup->add("drop the drill database {$name}", static function () use ($importer, $name): void {
                $importer->drop($name);
            });
            $importer->import($name, $file);
            $result['ok'] = true;
            $log->success("Imported {$dump->label()} into {$name}");
        } catch (InterruptedException $e) {
            throw $e;
        } catch (RefusedException $e) {
            $result['error'] = $e->getMessage();
        } catch (ArkhiveException $e) {
            $result['error'] = 'the test import of ' . $dump->label() . ' failed: ' . $e->getMessage();
        } finally {
            $dropFailure = $dropId === null ? null : $this->cleanup->runOne($dropId);
            if ($dropFailure !== null) {
                $log->warning("Could not drop the drill database: {$dropFailure}");
            }
            $importer->close();
        }
        if ($result['error'] !== null) {
            $log->error("Drill: {$result['error']}");
        }

        return $result;
    }

    private static function since(int $started): float
    {
        return (hrtime(true) - $started) / 1e9;
    }
}
