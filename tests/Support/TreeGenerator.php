<?php

declare(strict_types=1);

namespace Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic random file trees for exclusion property tests.
 *
 * The vocabulary avoids the documented corner cases where restic and 1.x legitimately differ
 * (a directory named like a file pattern, a name glued to a pattern such as "xnpm-debug.log", a "*"
 * in the middle of a pattern spanning directories).
 */
final class TreeGenerator
{
    public const DIRECTORIES = [
        'src', 'lib', 'app', 'logs', 'docs', 'node_modules', 'vendor', 'backups', 'tmp', 'cache', 'build', 'dist',
        '.git', '__pycache__', 'node', 'target', 'public', 'with space', 'ünïcödé', 'a', 'b', 'config',
    ];

    public const FILES = [
        'index.php', 'README.md', 'app.log', 'error.log', 'data.json', 'archive.tar.gz', 'archive.tar', 'notes.txt~',
        '.DS_Store', 'composer.lock', 'composer.json', 'package-lock.json', '.env.local', '.env.production.local', '.env',
        'photo.jpg', 'script.pyc', 'module.so', 'file with spaces.txt', 'ünï.txt', 'lib.a', 'Thumbs.db', 'app.sock',
        'yarn.lock', 'main.o', 'Main.class', 'disk.iso', 'run.pid',
    ];

    /**
     * @return list<string> relative file paths with a leading "/"
     */
    public static function files(int $seed, int $count = 120, int $maxDepth = 4): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $depth = $random->getInt(0, $maxDepth);
            $parts = [];
            for ($d = 0; $d < $depth; $d++) {
                $parts[] = self::DIRECTORIES[$random->getInt(0, count(self::DIRECTORIES) - 1)];
            }
            $parts[] = self::FILES[$random->getInt(0, count(self::FILES) - 1)];
            $paths['/' . implode('/', $parts)] = true;
        }

        return array_keys($paths);
    }
}
