<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\System;

/**
 * Where ArkHive keeps its state: $STATE_DIRECTORY (systemd's StateDirectory=), /var/lib/arkhive as
 * root, else $XDG_STATE_HOME/arkhive or ~/.local/state/arkhive.
 */
final class StateDirectory
{
    public const SYSTEM = '/var/lib/arkhive';

    /**
     * @param array<string, string> $environment
     */
    public static function resolve(array $environment, int $uid): ?string
    {
        $systemd = self::firstAbsolute($environment['STATE_DIRECTORY'] ?? '');
        if ($systemd !== null) {
            return $systemd;
        }
        if ($uid === 0) {
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
