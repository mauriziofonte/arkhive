<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Shared;

/**
 * Human-readable sizes in binary units, as restic prints them.
 */
final class Bytes
{
    private const UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

    public static function format(int|float $bytes): string
    {
        $value = (float) max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? sprintf('%d B', (int) $value) : sprintf('%.2f %s', $value, self::UNITS[$unit]);
    }
}
