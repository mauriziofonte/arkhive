<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Schedule;

use Mfonte\Arkhive\Application\System\BinaryFinder;

/**
 * How a scheduled job starts this ArkHive: the PHAR itself when its `#!/usr/bin/env php` shebang will
 * find php under systemd and cron (whose PATH holds only the standard directories), otherwise the
 * running PHP binary with the PHAR or, from source, the entry point.
 */
final class Invocation
{
    /**
     * @param string $phar the running PHAR, '' when running from source
     *
     * @return list<string>
     */
    public static function resolve(string $phar, string $source, string $phpBinary, BinaryFinder $standard): array
    {
        if ($phar === '') {
            return [$phpBinary, $source];
        }
        if (is_executable($phar) && $standard->find('php') !== null) {
            return [$phar];
        }

        return [$phpBinary, $phar];
    }

    /**
     * Only the standard directories, as cron's and systemd's PATH.
     */
    public static function standardFinder(): BinaryFinder
    {
        return new BinaryFinder('');
    }
}
