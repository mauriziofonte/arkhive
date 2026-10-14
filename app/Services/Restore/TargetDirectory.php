<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use DateTimeImmutable;
use FilesystemIterator;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Support\Bytes;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Where a restore writes, checked in PHP before anything is written: a new or empty directory,
 * enough free space on its filesystem. A target ArkHive created is removed again if the restore fails.
 */
final class TargetDirectory
{
    public function __construct(public readonly string $path)
    {
    }

    /**
     * ./arkhive-restore-<host>-<snapshot short id>-<YYYYmmdd-HHMMSS>, never the original location.
     */
    public static function defaultName(string $host, string $shortId, DateTimeImmutable $now): string
    {
        $host = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $host), '-.');

        return sprintf('arkhive-restore-%s-%s-%s', $host === '' ? 'host' : $host, $shortId, $now->format('Ymd-His'));
    }

    /**
     * Makes $path absolute against $cwd and normalises "." and ".." without touching the filesystem.
     */
    public static function absolute(string $path, string $cwd): string
    {
        if (trim($path) === '') {
            throw new UsageException('The restore target is empty');
        }
        if (!str_starts_with($path, '/')) {
            $path = rtrim($cwd, '/') . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    public function exists(): bool
    {
        clearstatcache(true, $this->path);

        return file_exists($this->path) || is_link($this->path);
    }

    public function isEmpty(): bool
    {
        if (!$this->exists()) {
            return true;
        }
        if (!is_dir($this->path)) {
            return false;
        }

        return !(new FilesystemIterator($this->path))->valid();
    }

    /**
     * @throws RefusedException a file instead of a directory, or a non-empty directory without $force
     */
    public function assertUsable(bool $force): void
    {
        if (is_link($this->path) || ($this->exists() && !is_dir($this->path))) {
            throw new RefusedException("The restore target {$this->path} exists and is not a directory", 'Choose a new directory as --target');
        }
        if (!$force && !$this->isEmpty()) {
            throw new RefusedException(
                "The restore target {$this->path} is not empty: ArkHive restores only into a new or empty directory",
                'Choose a new directory as --target, or pass --force to restore into it anyway (existing files with the same names are overwritten)',
            );
        }
        $parent = $this->existingAncestor();
        if (!is_writable($parent)) {
            throw new PermissionException("Cannot restore into {$this->path}: {$parent} is not writable by this user");
        }
    }

    /**
     * The nearest directory that exists: where the target will be created, and whose filesystem counts.
     */
    public function existingAncestor(): string
    {
        $path = $this->path;
        while ($path !== '/' && !is_dir($path)) {
            $path = dirname($path);
        }

        return $path;
    }

    public function freeSpace(): ?int
    {
        $free = @disk_free_space($this->existingAncestor());

        return $free === false ? null : (int) $free;
    }

    /**
     * @throws RefusedException not enough room for $needed bytes, both numbers in the message
     */
    public function assertSpace(int $needed, string $what = 'the restore'): void
    {
        $free = $this->freeSpace();
        if ($free === null || $needed <= $free) {
            return;
        }

        throw new RefusedException(
            sprintf('Not enough free space in %s for %s: %s needed, %s available', $this->existingAncestor(), $what, Bytes::format($needed), Bytes::format($free)),
            'Free some space, or choose a target on a larger filesystem',
        );
    }

    /**
     * A new directory only this user can enter (0700), with a random name under $parent.
     */
    public static function createPrivate(string $parent, string $prefix, string $purpose): string
    {
        for ($i = 0; $i < 10; $i++) {
            $dir = rtrim($parent, '/') . '/' . $prefix . bin2hex(random_bytes(6));
            if (@mkdir($dir, 0700)) {
                return $dir;
            }
        }

        throw new RefusedException("Cannot create a private directory in {$parent} for {$purpose}");
    }

    /**
     * Removes the tree without following symlinks; used only on a target this run created.
     */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            assert($item instanceof SplFileInfo);
            $name = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @chmod($name, 0700);
                @rmdir($name);
            } else {
                @unlink($name);
            }
        }
        @rmdir($path);
    }

    /**
     * Bytes currently used by regular files below $path (what an in-place restore overwrites).
     */
    public static function usedBytes(string $path): int
    {
        if (is_link($path)) {
            return 0;
        }
        if (is_file($path)) {
            return (int) filesize($path);
        }
        if (!is_dir($path)) {
            return 0;
        }
        $total = 0;
        try {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                assert($item instanceof SplFileInfo);
                if ($item->isFile() && !$item->isLink()) {
                    $total += $item->getSize();
                }
            }
        } catch (\UnexpectedValueException) {
            // an unreadable directory: count what could be read
        }

        return $total;
    }

    /**
     * Gives the tree to $uid:$gid without following symlinks (--owner current as root).
     *
     * @return int entries that could not be changed
     */
    public static function chownTree(string $path, int $uid, int $gid): int
    {
        if (!file_exists($path) && !is_link($path)) {
            return 0;
        }
        $failed = (@lchown($path, $uid) && @lchgrp($path, $gid)) ? 0 : 1;
        if (!is_dir($path) || is_link($path)) {
            return $failed;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            assert($item instanceof SplFileInfo);
            if (!(@lchown($item->getPathname(), $uid) && @lchgrp($item->getPathname(), $gid))) {
                $failed++;
            }
        }

        return $failed;
    }
}
