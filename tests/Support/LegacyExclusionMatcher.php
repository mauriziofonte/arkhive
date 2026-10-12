<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The 1.x exclusion matcher (FileEnumeratorService::convertPatternToRegex), kept verbatim in behaviour
 * so the conversion to restic patterns can be checked against what 1.x actually excluded.
 */
final class LegacyExclusionMatcher
{
    /**
     * @param list<string> $patterns
     * @param string       $relativePath path of a file relative to the backup root, with a leading "/"
     */
    public static function excludes(array $patterns, string $relativePath): bool
    {
        foreach ($patterns as $pattern) {
            if (trim($pattern) !== '' && preg_match(self::regex($pattern), $relativePath) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function regex(string $pattern): string
    {
        $isAnchored = false;
        $hasTrailingWildcard = false;
        if (str_starts_with($pattern, '/')) {
            $isAnchored = true;
            $pattern = substr($pattern, 1);
            if (str_ends_with($pattern, '/*')) {
                $hasTrailingWildcard = true;
                $pattern = substr($pattern, 0, -2);
            }
        }
        $placeholder = '___WILDCARD___';
        $converted = str_replace($placeholder, '.*', preg_quote(str_replace('*', $placeholder, $pattern), '#'));
        if ($isAnchored) {
            $regex = $hasTrailingWildcard ? '^/' . $converted . '/.*' : '^/' . $converted . '$';
        } else {
            if (!str_starts_with($converted, '.*')) {
                $converted = '.*' . $converted;
            }
            $regex = $converted . '$';
        }

        return '#' . $regex . '#';
    }
}
