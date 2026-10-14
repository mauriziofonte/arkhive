<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\RefusedException;

/**
 * DUMP_DIRECTORY: one plain file per database in mysql/<db>.sql and pgsql/<db>.sql. A dump is written
 * as <db>.sql.partial and renamed when complete. Only dump files are ever removed (ArkHive's own
 * subdirectories, *.sql and *.sql.partial); anything else in the directory is left alone.
 */
final class DumpStaging
{
    public const ENGINES = ['mysql', 'pgsql'];

    /** @var array<string, true> */
    private array $written = [];

    public function __construct(public readonly string $directory)
    {
    }

    /**
     * Creates the directory (0700) and its subdirectories, and removes dump files left behind by a run
     * that was killed before cleaning up (they would otherwise go into the snapshot as current dumps).
     *
     * @return list<string> the leftover files removed
     */
    public function prepare(): array
    {
        $removed = [];
        foreach (['', ...self::ENGINES] as $sub) {
            $dir = $sub === '' ? $this->directory : "{$this->directory}/{$sub}";
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RefusedException("Cannot create the dump directory {$dir}", hint: 'Check DUMP_DIRECTORY and the permissions of its parent');
            }
            if (!is_writable($dir)) {
                throw new PermissionException("The dump directory {$dir} is not writable");
            }
            if ($sub !== '') {
                foreach (self::dumpFiles($dir) as $file) {
                    if (@unlink($file)) {
                        $removed[] = $file;
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Final path of a database dump. Names are percent-encoded so that any database name is a safe,
     * reversible file name.
     */
    public function pathFor(string $engine, string $database): string
    {
        return "{$this->directory}/{$engine}/" . rawurlencode($database) . '.sql';
    }

    public static function partial(string $path): string
    {
        return $path . '.partial';
    }

    public function record(string $path): void
    {
        $this->written[$path] = true;
    }

    /**
     * @return list<string>
     */
    public function written(): array
    {
        return array_keys($this->written);
    }

    /**
     * Removes the dumps of this run (complete and partial). Returns how many files were removed.
     */
    public function clear(): int
    {
        $removed = 0;
        foreach (self::ENGINES as $engine) {
            foreach (self::dumpFiles("{$this->directory}/{$engine}") as $file) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }
        $this->written = [];

        return $removed;
    }

    public function freeSpace(): ?float
    {
        $free = @disk_free_space($this->directory);

        return $free === false ? null : $free;
    }

    /**
     * @return list<string>
     */
    private static function dumpFiles(string $dir): array
    {
        $files = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            $path = "{$dir}/{$name}";
            if ((str_ends_with($name, '.sql') || str_ends_with($name, '.sql.partial')) && is_file($path) && !is_link($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
