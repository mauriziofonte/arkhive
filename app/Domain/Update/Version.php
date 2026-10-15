<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Update;

/**
 * Release versions: a tag "v2.0.1" or "2.0.0-rc.1", compared semantically (spec §9).
 */
final class Version
{
    private const PATTERN = '/^\d+\.\d+\.\d+(-[0-9A-Za-z][0-9A-Za-z.-]*)?$/';

    /**
     * The version without the "v" prefix, or null when it is not semantic.
     */
    public static function normalize(string $tag): ?string
    {
        $version = preg_replace('/^[vV]/', '', trim($tag));
        if (!is_string($version) || preg_match(self::PATTERN, $version) !== 1) {
            return null;
        }

        return $version;
    }

    /**
     * <0, 0 or >0. Pre-releases sort before their release: 2.0.0-rc.1 < 2.0.0 < 2.0.1.
     */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::canonical($a), self::canonical($b));
    }

    public static function major(string $version): int
    {
        return (int) explode('.', self::canonical($version))[0];
    }

    public static function isPrerelease(string $version): bool
    {
        return str_contains(self::canonical($version), '-');
    }

    private static function canonical(string $version): string
    {
        return self::normalize($version) ?? ltrim(trim($version), 'vV');
    }
}
