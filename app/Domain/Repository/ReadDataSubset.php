<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use Closure;

/**
 * The `check --read-data-subset` value for the installed restic. RESTIC_CHECK_SUBSET accepts a
 * percentage (5%), a group (1/10) or a size (500M); restic understands percentages from 0.12.0 and
 * sizes from 0.14.0, so older releases get the nearest group "n/N".
 */
final class ReadDataSubset
{
    private const UNITS = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4];

    /**
     * @param Closure(): int $repositoryBytes the stored size, asked only to turn a size into a fraction
     * @param int            $seed            picks which group is read, so that runs cover the whole repository over time
     */
    public static function adapt(string $subset, ResticCapabilities $capabilities, Closure $repositoryBytes, int $seed): string
    {
        if (preg_match('/^(\d+)([kKmMgGtT]?)$/', $subset, $size) === 1) {
            if ($capabilities->sizeSubset()) {
                return $subset;
            }
            $total = $repositoryBytes();
            $bytes = (int) $size[1] * self::UNITS[strtolower($size[2])];
            $percent = $total <= 0 ? 100.0 : min(100.0, 100.0 * $bytes / $total);

            return self::percent($percent, $capabilities, $seed);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)%$/', $subset, $match) === 1) {
            return $capabilities->percentSubset() ? $subset : self::group((float) $match[1], $seed);
        }

        return $subset;
    }

    private static function percent(float $percent, ResticCapabilities $capabilities, int $seed): string
    {
        if (!$capabilities->percentSubset()) {
            return self::group($percent, $seed);
        }
        $text = rtrim(rtrim(sprintf('%.2f', max(0.01, $percent)), '0'), '.');

        return "{$text}%";
    }

    /**
     * One group of N, N chosen so that a group is the nearest to $percent (5% is 1 of 20).
     */
    private static function group(float $percent, int $seed): string
    {
        $groups = $percent <= 0.0 ? 100 : max(1, min(10000, (int) round(100 / $percent)));

        return sprintf('%d/%d', (abs($seed) % $groups) + 1, $groups);
    }
}
