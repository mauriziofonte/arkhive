<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Exclusion;

/**
 * Converts 1.x EXCLUSION_PATTERNS to restic exclude patterns (spec §5, with the anchoring fix noted below).
 *
 * 1.x matched each pattern, with "*" crossing directory separators, against the path of every file
 * relative to the backup root. The conversion:
 *  - a leading "/" anchors the pattern to the backup root: "/logs/*" becomes "<each BACKUP_PATH>/logs/*";
 *  - otherwise leading "* /" and trailing "/ *" (without the spaces) are dropped, so "* /vendor/ *" and
 *    "node_modules/ *" name a directory and "*.log" a file name, and the result is anchored below each
 *    backup path with "**": "<BACKUP_PATH>/** /vendor", "<BACKUP_PATH>/** /*.log".
 *
 * The anchoring is needed because restic matches a bare pattern such as "tmp" against every component
 * of the absolute path, including the directories above the backup root: a backup path like
 * /var/tmp/app would otherwise be excluded entirely, which 1.x never did.
 */
final class ExclusionConverter
{
    /**
     * Splits the EXCLUSION_PATTERNS value (comma-separated, as in 1.x).
     *
     * @return list<string>
     */
    public static function split(string $value): array
    {
        $patterns = [];
        foreach (explode(',', $value) as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * @param list<string> $patterns    1.x patterns
     * @param list<string> $backupPaths absolute BACKUP_PATHS, for anchored patterns
     *
     * @return list<string> restic patterns, unescaped
     */
    public static function convert(array $patterns, array $backupPaths): array
    {
        $converted = [];
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, '/')) {
                $body = trim($pattern, '/');
                if ($body === '') {
                    continue;
                }
                foreach ($backupPaths as $root) {
                    $converted[] = self::root($root) . '/' . $body;
                }

                continue;
            }

            while (str_starts_with($pattern, '*/')) {
                $pattern = substr($pattern, 2);
            }
            while (str_ends_with($pattern, '/*')) {
                $pattern = substr($pattern, 0, -2);
            }
            $pattern = trim($pattern, '/');
            if ($pattern === '') {
                continue;
            }
            foreach ($backupPaths as $root) {
                $converted[] = self::root($root) . '/**/' . $pattern;
            }
        }

        return array_values(array_unique($converted));
    }

    /**
     * A backup path as a literal pattern prefix: glob metacharacters escaped, no trailing "/".
     */
    private static function root(string $path): string
    {
        $path = rtrim($path, '/');

        return (string) preg_replace('/([*?\[\]\\\\])/', '\\\\$1', $path);
    }

    /**
     * Renders patterns as the content of a restic --exclude-file. restic expands $VARIABLES and treats
     * lines starting with "#" as comments and "!" as negations, so those characters are escaped.
     *
     * @param list<string> $patterns restic patterns from convert()
     */
    public static function excludeFile(array $patterns): string
    {
        $lines = [];
        foreach ($patterns as $pattern) {
            $line = str_replace('$', '$$', $pattern);
            if (str_starts_with($line, '#') || str_starts_with($line, '!')) {
                $line = '\\' . $line;
            }
            $lines[] = $line;
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }
}
