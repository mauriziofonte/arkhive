<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\System;

use Closure;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\IoException;

/**
 * Free space on the filesystem of a path (that may not exist yet), and the free space found added to
 * the report of a write that failed, so that "disk full" names how full and where.
 */
final class DiskSpace
{
    /**
     * The nearest directory that exists: the one whose filesystem a new $path would be on.
     */
    public static function nearestDirectory(string $path): string
    {
        while ($path !== '/' && $path !== '.' && !is_dir($path)) {
            $path = dirname($path);
        }

        return $path;
    }

    public static function available(string $path): ?int
    {
        $free = @disk_free_space(self::nearestDirectory($path));

        return $free === false ? null : (int) $free;
    }

    /**
     * "1.20 GiB free in /var/backups"
     */
    public static function describe(string $path): string
    {
        $directory = self::nearestDirectory($path);
        $free = self::available($directory);

        return $free === null ? "free space in {$directory} unknown" : Bytes::format($free) . " free in {$directory}";
    }

    /**
     * The same failure, its message followed by the free space found on each filesystem written to.
     *
     * @param list<string> $paths
     */
    public static function annotate(IoException $failure, array $paths): IoException
    {
        $paths = array_values(array_unique(array_map(self::nearestDirectory(...), $paths)));
        if ($paths === []) {
            return $failure;
        }

        return new IoException(
            $failure->getMessage() . ' (' . implode(', ', array_map(self::describe(...), $paths)) . ')',
            $failure->hint(),
            $failure,
        );
    }

    /**
     * Runs $operation; a write failure (exit 74) is rethrown with the free space found in $paths.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public static function whenFull(Closure $operation, string ...$paths): mixed
    {
        try {
            return $operation();
        } catch (IoException $e) {
            throw self::annotate($e, array_values($paths));
        }
    }
}
