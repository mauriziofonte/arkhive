<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Dump\DumpStaging;
use Mfonte\Arkhive\Services\Restic\Node;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restic\Snapshot;

/**
 * The database dumps in a snapshot. The dump directory is backed up as a path of its own, so dumps
 * are <snapshot path>/mysql/<name>.sql and <snapshot path>/pgsql/<name>.sql.
 */
final class DumpFinder
{
    /**
     * @return list<DumpFile>
     */
    public static function find(Restic $restic, Snapshot $snapshot): array
    {
        $directories = [];
        foreach ($snapshot->paths as $path) {
            foreach (DumpStaging::ENGINES as $engine) {
                $directories[] = rtrim($path, '/') . "/{$engine}";
            }
        }
        if ($directories === []) {
            return [];
        }
        $dumps = [];
        $restic->ls($snapshot->id, $directories, false, static function (Node $node) use (&$dumps, $directories): void {
            $dump = self::fromNode($node, $directories);
            if ($dump !== null) {
                $dumps[] = $dump;
            }
        });
        usort($dumps, static fn (DumpFile $a, DumpFile $b): int => [$a->engine, $a->database] <=> [$b->engine, $b->database]);

        return $dumps;
    }

    /**
     * @param list<string> $directories
     */
    public static function fromNode(Node $node, array $directories): ?DumpFile
    {
        if (!$node->isFile() || !str_ends_with($node->path, '.sql') || !in_array(dirname($node->path), $directories, true)) {
            return null;
        }

        return new DumpFile(basename(dirname($node->path)), rawurldecode(basename($node->path, '.sql')), $node->path, $node->size);
    }

    /**
     * --db: a database name, or engine/name when both engines have a dump with that name.
     *
     * @param list<DumpFile> $dumps
     */
    public static function select(array $dumps, string $name): DumpFile
    {
        $engine = null;
        if (preg_match('#^(mysql|pgsql)/(.+)$#', $name, $m) === 1) {
            [$engine, $name] = [$m[1], $m[2]];
        }
        $matches = array_values(array_filter($dumps, static fn (DumpFile $d): bool => $d->database === $name && ($engine === null || $d->engine === $engine)));
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            $available = $dumps === [] ? 'the snapshot has no database dump' : 'available: ' . implode(', ', array_map(static fn (DumpFile $d): string => $d->label(), $dumps));
            throw new UsageException("There is no dump of database {$name} in the snapshot ({$available})");
        }

        throw new UsageException("Both a MySQL and a PostgreSQL dump of {$name} are in the snapshot", "Pass --db mysql/{$name} or --db pgsql/{$name}");
    }
}
