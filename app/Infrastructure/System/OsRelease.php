<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\System;

use Mfonte\Arkhive\Domain\System\Distro;

/**
 * Reads the distribution from /etc/os-release, or /usr/lib/os-release where /etc has none.
 */
final class OsRelease
{
    public const FILES = ['/etc/os-release', '/usr/lib/os-release'];

    /**
     * @param list<string> $files
     */
    public static function detect(array $files = self::FILES): Distro
    {
        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) {
                $content = file_get_contents($file);
                if ($content !== false) {
                    return Distro::fromOsRelease($content);
                }
            }
        }

        return new Distro('unknown');
    }
}
