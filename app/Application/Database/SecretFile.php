<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\RefusedException;

/**
 * A temporary file readable only by the running user, for credentials that must not appear on a
 * command line (MySQL --defaults-extra-file, PostgreSQL PGPASSFILE). Always removed by the caller
 * in a finally block, and by the cleanup registry if the process is interrupted.
 */
final class SecretFile
{
    private bool $removed = false;

    private function __construct(public readonly string $path)
    {
    }

    public static function create(string $prefix, string $content, ?string $directory = null): self
    {
        $directory ??= sys_get_temp_dir();
        // tempnam() creates the file with mode 0600 before anything is written to it
        // tempnam() silently falls back to the system temp dir when $directory is unusable
        $path = is_dir($directory) ? @tempnam($directory, $prefix) : false;
        if ($path !== false && realpath(dirname($path)) !== realpath($directory)) {
            @unlink($path);
            $path = false;
        }
        if ($path === false || !is_file($path)) {
            throw new RefusedException("Cannot create a temporary credentials file in {$directory}", hint: 'Check that the temp directory (TMPDIR) exists and is writable');
        }
        $file = new self($path);
        if (!@chmod($path, 0600) || @file_put_contents($path, $content) !== strlen($content)) {
            $file->remove();

            throw new IoException("Cannot write the temporary credentials file {$path}");
        }

        return $file;
    }

    public function remove(): void
    {
        if ($this->removed) {
            return;
        }
        $this->removed = true;
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
