<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Configuration;

use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\PermissionException;

/**
 * The repository password file must be private to the running user (spec §4).
 */
final class PasswordFile
{
    public static function assertSafe(string $path, string $origin = 'RESTIC_PASSWORD_FILE'): void
    {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            throw new NoInputException("{$origin} {$path} does not exist");
        }
        if (!is_file($path)) {
            throw new NoInputException("{$origin} {$path} is not a regular file");
        }

        $mode = fileperms($path) & 0777;
        if (($mode & 0044) !== 0) {
            throw new PermissionException(
                sprintf('%s %s is readable by group or others (mode %04o)', $origin, $path, $mode),
                "Run: chmod 600 {$path}",
            );
        }

        $owner = fileowner($path);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : $owner;
        if ($owner !== $uid) {
            throw new PermissionException(
                "{$origin} {$path} is owned by uid {$owner}, not by the running user (uid {$uid})",
                "Run: chown {$uid} {$path}",
            );
        }

        if (!is_readable($path)) {
            throw new PermissionException("{$origin} {$path} is not readable");
        }
        if (filesize($path) === 0) {
            throw new ConfigException("{$origin} {$path} is empty");
        }
    }
}
