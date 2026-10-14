<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\System;

/**
 * Finds executables by scanning PATH in PHP (no `command -v` through a shell). The standard
 * directories are searched after PATH because cron starts jobs with PATH=/usr/bin:/bin, which would
 * miss binaries installed in /usr/local/bin.
 */
final class BinaryFinder
{
    public const STANDARD_DIRECTORIES = ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'];

    /**
     * @param list<string> $standardDirectories
     */
    public function __construct(
        private readonly string $path,
        private readonly array $standardDirectories = self::STANDARD_DIRECTORIES,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $path = getenv('PATH');

        return new self(is_string($path) ? $path : '');
    }

    /**
     * Absolute path of $name, or null. A name containing a slash is checked as given.
     */
    public function find(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        if (str_contains($name, '/')) {
            return self::isExecutable($name) ? $name : null;
        }
        foreach ($this->directories() as $directory) {
            $candidate = rtrim($directory, '/') . '/' . $name;
            if (self::isExecutable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first of $names that exists (e.g. mariadb-dump before mysqldump).
     *
     * @param list<string> $names
     */
    public function first(array $names): ?string
    {
        foreach ($names as $name) {
            $found = $this->find($name);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        $directories = array_filter(explode(':', $this->path), static fn (string $dir): bool => str_starts_with($dir, '/'));

        return array_values(array_unique([...$directories, ...$this->standardDirectories]));
    }

    private static function isExecutable(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }
}
