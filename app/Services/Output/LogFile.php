<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Output;

use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * --log-file: lines are appended; when the file grows past $maxBytes it is rotated to
 * <path>.1 ... <path>.<keep> (oldest dropped).
 */
final class LogFile
{
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;
    public const DEFAULT_KEEP = 5;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $keep = self::DEFAULT_KEEP,
    ) {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir) || (file_exists($path) && !is_writable($path))) {
            throw new UsageException("Cannot write the log file {$path}: check that its directory exists and is writable");
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function write(string $line): void
    {
        clearstatcache(true, $this->path);
        if (is_file($this->path) && filesize($this->path) >= $this->maxBytes) {
            $this->rotate();
        }
        // a log write failure must never break the operation being logged
        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function rotate(): void
    {
        @unlink("{$this->path}.{$this->keep}");
        for ($i = $this->keep - 1; $i >= 1; $i--) {
            if (is_file("{$this->path}.{$i}")) {
                @rename("{$this->path}.{$i}", "{$this->path}." . ($i + 1));
            }
        }
        @rename($this->path, "{$this->path}.1");
    }
}
