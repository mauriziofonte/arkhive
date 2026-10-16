<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\System;

/**
 * Where restic keeps its cache: under systemd's CacheDirectory= ($CACHE_DIRECTORY) when the unit
 * sets one, so that a hardened unit (ProtectHome=, a read-only /root) still has a writable cache.
 * Otherwise restic chooses ($XDG_CACHE_HOME or ~/.cache), and a RESTIC_CACHE_DIR the operator
 * exported always wins. With neither HOME nor XDG_CACHE_HOME (env -i, a bare wrapper) restic has
 * no cache directory and refuses to run: the cache goes where HOME would have put it, under the
 * home directory of the running user's account.
 */
final class CacheDirectory
{
    /**
     * @param array<string, string> $environment
     * @param string|null           $accountHome the home directory of the running user's account (passwd)
     */
    public static function restic(array $environment, ?string $accountHome = null): ?string
    {
        if (($environment['RESTIC_CACHE_DIR'] ?? '') !== '') {
            return null;
        }
        $systemd = StateDirectory::firstAbsolute($environment['CACHE_DIRECTORY'] ?? '');
        if ($systemd !== null) {
            return ($systemd === '/' ? '' : $systemd) . '/restic';
        }
        if (($environment['XDG_CACHE_HOME'] ?? '') !== '' || ($environment['HOME'] ?? '') !== '') {
            return null;
        }
        if ($accountHome === null || !str_starts_with($accountHome, '/') || rtrim($accountHome, '/') === '') {
            return null;
        }

        return rtrim($accountHome, '/') . '/.cache/restic';
    }

    /**
     * The home directory of the account ArkHive runs as, from the password database.
     */
    public static function accountHome(): ?string
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            return null;
        }
        $account = posix_getpwuid(posix_geteuid());

        return is_array($account) && is_string($account['dir'] ?? null) && $account['dir'] !== '' ? $account['dir'] : null;
    }
}
