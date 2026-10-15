<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\System;

/**
 * Where restic keeps its cache: under systemd's CacheDirectory= ($CACHE_DIRECTORY) when the unit
 * sets one, so that a hardened unit (ProtectHome=, a read-only /root) still has a writable cache.
 * Otherwise restic chooses ($XDG_CACHE_HOME or ~/.cache), and a RESTIC_CACHE_DIR the operator
 * exported always wins.
 */
final class CacheDirectory
{
    /**
     * @param array<string, string> $environment
     */
    public static function restic(array $environment): ?string
    {
        if (($environment['RESTIC_CACHE_DIR'] ?? '') !== '') {
            return null;
        }
        $systemd = StateDirectory::firstAbsolute($environment['CACHE_DIRECTORY'] ?? '');
        if ($systemd === null) {
            return null;
        }

        return ($systemd === '/' ? '' : $systemd) . '/restic';
    }
}
