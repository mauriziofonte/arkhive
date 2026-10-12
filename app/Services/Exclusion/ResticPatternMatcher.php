<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Exclusion;

/**
 * Re-implements restic's exclude matching (internal/filter: Match) to answer "would restic exclude this
 * path?" without running restic, e.g. to refuse a DUMP_DIRECTORY that an exclusion would drop.
 *
 * A pattern is split on "/". An absolute pattern must match the leading components of the path; a
 * relative one any run of consecutive components. A match on a directory excludes everything below it.
 * Each component is matched like Go's filepath.Match ("*", "?", "[...]", "\" escapes); "**" matches
 * any number of components.
 */
final class ResticPatternMatcher
{
    /**
     * @param list<string> $patterns restic patterns
     */
    public static function excludes(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (self::match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public static function match(string $pattern, string $path): bool
    {
        $pattern = self::clean($pattern);
        if ($pattern === '') {
            return false;
        }

        return self::matchParts(explode('/', $pattern), explode('/', self::clean($path)));
    }

    /**
     * @param list<string> $patternParts
     * @param list<string> $pathParts
     */
    private static function matchParts(array $patternParts, array $pathParts): bool
    {
        $doubleStar = array_search('**', $patternParts, true);
        if ($doubleStar !== false) {
            // expand "**" into 0..n single wildcards, like restic does
            $before = array_slice($patternParts, 0, $doubleStar);
            $after = array_slice($patternParts, $doubleStar + 1);
            for ($n = 0; $n <= count($pathParts); $n++) {
                if (self::matchParts(array_merge($before, array_fill(0, $n, '*'), $after), $pathParts)) {
                    return true;
                }
            }

            return false;
        }

        $patternCount = count($patternParts);
        $pathCount = count($pathParts);
        if ($patternCount === 0 || $pathCount === 0 || $patternCount > $pathCount) {
            return false;
        }

        $minOffset = 0;
        $maxOffset = $pathCount - $patternCount;
        if ($patternParts[0] === '') {
            $maxOffset = 0;
        } elseif ($pathParts[0] === '') {
            $minOffset = 1;
        }

        for ($offset = $maxOffset; $offset >= $minOffset; $offset--) {
            $matched = true;
            for ($i = $patternCount - 1; $i >= 0; $i--) {
                if (!self::matchComponent($patternParts[$i], $pathParts[$offset + $i])) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Go filepath.Match for a single component (no separators inside).
     */
    public static function matchComponent(string $pattern, string $name): bool
    {
        return preg_match(self::componentRegex($pattern), $name) === 1;
    }

    private static function componentRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '#');
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[' && ($close = self::classEnd($pattern, $i)) !== null) {
                $class = substr($pattern, $i + 1, $close - $i - 1);
                $negated = str_starts_with($class, '^');
                $class = $negated ? substr($class, 1) : $class;
                $regex .= '[' . ($negated ? '^' : '') . str_replace(['\\', ']'], ['\\\\', '\\]'], $class) . ']';
                $i = $close;
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#^' . $regex . '$#su';
    }

    private static function classEnd(string $pattern, int $open): ?int
    {
        $close = strpos($pattern, ']', $open + 2);

        return $close === false ? null : $close;
    }

    /**
     * filepath.Clean for the cases that matter here: collapses "//", drops "." and a trailing "/".
     */
    private static function clean(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $absolute = str_starts_with($path, '/');
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== '' && $p !== '.'));

        return ($absolute ? '/' : '') . implode('/', $parts);
    }
}
