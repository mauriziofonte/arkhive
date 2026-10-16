<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\RefusedException;

/**
 * DUMP_DIRECTORY: one plain file per database in mysql/<db>.sql and pgsql/<db>.sql. A dump is written
 * as <db>.sql.partial and renamed when complete. Only dump files are ever removed (ArkHive's own
 * subdirectories, *.sql and *.sql.partial); anything else in the directory is left alone.
 *
 * Dumps hold every row of every table, so they are private to the running user: the directory must
 * be its own and closed to writes by others, the engine subdirectories are 0700, and each dump file
 * is created 0600 before the dump tool writes into it (the tools keep the mode of an existing file,
 * whatever the umask).
 */
final class DumpStaging
{
    public const ENGINES = ['mysql', 'pgsql'];

    /** @var array<string, true> */
    private array $written = [];

    private readonly int $uid;

    /**
     * @param int|null $uid the user the dumps belong to; null: the effective user
     */
    public function __construct(public readonly string $directory, ?int $uid = null)
    {
        $this->uid = $uid ?? posix_geteuid();
    }

    /**
     * Creates the directory (0700) and its subdirectories, makes ArkHive's subdirectories private,
     * and removes dump files left behind by a run that was killed before cleaning up (they would
     * otherwise go into the snapshot as current dumps).
     *
     * @return list<string> the leftover files removed
     */
    public function prepare(): array
    {
        $removed = [];
        foreach (['', ...self::ENGINES] as $sub) {
            $dir = $sub === '' ? $this->directory : "{$this->directory}/{$sub}";
            if (!is_dir($dir) && !is_link($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RefusedException("Cannot create the dump directory {$dir}", hint: 'Check DUMP_DIRECTORY and the permissions of its parent');
            }
            $this->assertPrivate($dir, $sub !== '');
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
     * The empty, private (0600) file a dump tool writes the dump of $path into; a stale one is
     * replaced.
     */
    public function createPartial(string $path): string
    {
        $partial = self::partial($path);
        if (is_link($partial) || is_file($partial)) {
            @unlink($partial);
        }
        $umask = umask(0077);
        try {
            $handle = @fopen($partial, 'x');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RefusedException("Cannot create the dump file {$partial}", hint: 'Check that the dump directory is writable by this user');
        }
        fclose($handle);
        @chmod($partial, 0600);

        return $partial;
    }

    /**
     * Renames a complete dump to its final path, private (0600). False when the dump tool wrote
     * nothing (a real dump starts with a header) or the rename failed.
     */
    public function publish(string $partial, string $path): bool
    {
        clearstatcache(true, $partial);
        if (!is_file($partial) || is_link($partial) || (int) filesize($partial) === 0 || !@rename($partial, $path)) {
            return false;
        }
        @chmod($path, 0600);
        clearstatcache(true, $path);

        return true;
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
     * DUMP_DIRECTORY must belong to the running user and be closed to writes by group or others;
     * ArkHive's own subdirectories (mysql, pgsql) are made 0700 when they are not.
     */
    private function assertPrivate(string $dir, bool $own): void
    {
        clearstatcache(true, $dir);
        $stat = $own ? @lstat($dir) : @stat($dir);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new PermissionException(
                "The dump directory {$dir} is not a directory" . ($own && is_link($dir) ? ' but a symbolic link' : ''),
                $own ? "Remove {$dir}: ArkHive creates it" : 'Set DUMP_DIRECTORY to a directory',
            );
        }
        if ($stat['uid'] !== $this->uid) {
            throw new PermissionException(
                "The dump directory {$dir} belongs to uid {$stat['uid']}, not to the running user (uid {$this->uid}): its owner could read every dump",
                $own ? "Remove {$dir}: ArkHive creates it" : "Run: chown {$this->uid} {$dir}, or set DUMP_DIRECTORY to a directory of this user",
            );
        }
        $mode = $stat['mode'] & 07777;
        if (!$own) {
            if (($mode & 0022) !== 0) {
                throw new PermissionException(
                    sprintf('The dump directory %s is writable by group or others (mode %04o): they could read or replace the dumps', $dir, $mode),
                    "Run: chmod 700 {$dir}",
                );
            }

            return;
        }
        if (($mode & 0077) !== 0 && !@chmod($dir, 0700)) {
            throw new PermissionException(sprintf('The dump directory %s is open to group or others (mode %04o) and cannot be made private', $dir, $mode), "Run: chmod 700 {$dir}");
        }
        clearstatcache(true, $dir);
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
