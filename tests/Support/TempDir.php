<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\After;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Private temporary directories, removed after each test.
 */
trait TempDir
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/arkhive-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    #[After]
    protected function removeTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    protected static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0700);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @chmod($path, 0700);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
