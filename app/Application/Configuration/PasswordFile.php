<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Configuration;

use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\PermissionException;

/**
 * The repository password file must be private to the running user (spec §4): a regular file (not
 * a symbolic link, which someone could point elsewhere), owned by that user, with no permission for
 * group or others, in a directory that group or others cannot write (they could replace the file
 * between this check and restic reading it, and `init` would then create the repository with a
 * password they know), and that belongs to the running user or root (its owner can do the same
 * whatever its mode). A sticky directory (/tmp) is accepted: there only the owner can rename or
 * remove the file.
 */
final class PasswordFile
{
    public static function assertSafe(string $path, string $origin = 'RESTIC_PASSWORD_FILE'): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            throw new NoInputException("{$origin} {$path} does not exist");
        }
        if (($stat['mode'] & 0170000) === 0120000) {
            $target = readlink($path);
            throw new PermissionException(
                "{$origin} {$path} is a symbolic link" . ($target === false ? '' : " (to {$target})") . ': whoever can change the link chooses the password',
                "Point {$origin} at the file itself",
            );
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            throw new NoInputException("{$origin} {$path} is not a regular file");
        }

        $mode = $stat['mode'] & 0777;
        if (($mode & 0077) !== 0) {
            $access = match (true) {
                ($mode & 0044) !== 0 => 'readable',
                ($mode & 0022) !== 0 => 'writable',
                default => 'accessible',
            };
            throw new PermissionException(
                sprintf('%s %s is %s by group or others (mode %04o)', $origin, $path, $access, $mode),
                "Run: chmod 600 {$path}",
            );
        }

        $owner = $stat['uid'];
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : $owner;
        if ($owner !== $uid) {
            throw new PermissionException(
                "{$origin} {$path} is owned by uid {$owner}, not by the running user (uid {$uid})",
                "Run: chown {$uid} {$path}",
            );
        }

        self::assertPrivateDirectory($path, $origin);

        if (!is_readable($path)) {
            throw new PermissionException("{$origin} {$path} is not readable");
        }
        if ($stat['size'] === 0) {
            throw new ConfigException("{$origin} {$path} is empty");
        }
    }

    /**
     * The directory of the password file: group or others must not be able to write it, unless it
     * is sticky, and it must belong to the running user or root. `init` checks it before it
     * generates a password there.
     */
    public static function assertPrivateDirectory(string $path, string $origin = 'RESTIC_PASSWORD_FILE'): void
    {
        $directory = dirname($path);
        $stat = @stat($directory);
        if ($stat === false) {
            return;
        }
        $mode = $stat['mode'] & 07777;
        if (($mode & 0022) !== 0 && ($mode & 01000) === 0) {
            throw new PermissionException(
                sprintf('%s %s is in %s, which group or others can write (mode %04o): they could replace the file with one whose password they know', $origin, $path, $directory, $mode),
                "Run: chmod go-w {$directory}, or move the password file to a directory only its owner can write",
            );
        }
        $owner = $stat['uid'];
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : $owner;
        if ($owner !== $uid && $owner !== 0) {
            throw new PermissionException(
                "{$origin} {$path} is in {$directory}, which belongs to uid {$owner}, not to the running user (uid {$uid}) or root: its owner could replace the file, or remove it and stop every run",
                "Move the password file to a directory owned by uid {$uid} or root (such as /etc/arkhive), or run: chown {$uid} {$directory}",
            );
        }
    }
}
