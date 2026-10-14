<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\System;

/**
 * Where ArkHive keeps its state: $STATE_DIRECTORY (systemd's StateDirectory=), /var/lib/arkhive as
 * root or when it belongs to this user (the units' StateDirectory=arkhive with User=), else
 * $XDG_STATE_HOME/arkhive or ~/.local/state/arkhive.
 */
final class StateDirectory
{
    public const SYSTEM = '/var/lib/arkhive';

    /**
     * @param array<string, string> $environment
     * @param int|null              $systemOwner the owner of /var/lib/arkhive, null when it does not exist
     */
    public static function resolve(array $environment, int $uid, ?int $systemOwner = null): ?string
    {
        $systemd = self::firstAbsolute($environment['STATE_DIRECTORY'] ?? '');
        if ($systemd !== null) {
            return $systemd;
        }
        if ($uid === 0 || $systemOwner === $uid) {
            // a run by hand finds what the scheduled runs of the same user recorded
            return self::SYSTEM;
        }
        $xdg = $environment['XDG_STATE_HOME'] ?? '';
        if (str_starts_with($xdg, '/')) {
            return rtrim($xdg, '/') . '/arkhive';
        }
        $home = $environment['HOME'] ?? '';

        return str_starts_with($home, '/') ? rtrim($home, '/') . '/.local/state/arkhive' : null;
    }

    /**
     * The owner of /var/lib/arkhive, or null when it is not a directory.
     */
    public static function systemOwner(string $directory = self::SYSTEM): ?int
    {
        if (!is_dir($directory)) {
            return null;
        }
        $owner = @fileowner($directory);

        return $owner === false ? null : $owner;
    }

    /**
     * systemd passes one absolute path per StateDirectory= entry, separated by colons.
     */
    public static function firstAbsolute(string $list): ?string
    {
        foreach (explode(':', $list) as $path) {
            if (str_starts_with($path, '/')) {
                return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
            }
        }

        return null;
    }
}
