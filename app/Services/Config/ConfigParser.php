<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Config;

use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\NoInputException;

/**
 * Parses the .env-style configuration into raw strings. Values are never interpreted here: typing
 * happens in ConfigFactory.
 *
 * Syntax, one assignment per line: `KEY=value`, optionally prefixed by `export `.
 *  - 'single quotes' keep everything literally;
 *  - "double quotes" accept the escapes \\ \" \$ \n \t;
 *  - unquoted values end at a " #" comment and are trimmed;
 *  - lines starting with # are comments; there is no variable expansion.
 * A line that cannot be parsed, an unterminated quote or a key set twice is an error naming the line,
 * so no setting is ever dropped silently (vlucas/phpdotenv, used by 1.x, drops some quoted values).
 */
final class ConfigParser
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $content, string $source): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        $values = [];
        $definedAt = [];
        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $line = ltrim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^export\s+/', $line, $m) === 1) {
                $line = substr($line, strlen($m[0]));
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/s', $line, $m) !== 1) {
                throw self::error($source, $number, 'expected KEY=value');
            }
            [, $key, $raw] = $m;
            if (isset($definedAt[$key])) {
                throw self::error($source, $number, "{$key} is already set on line {$definedAt[$key]}");
            }
            $values[$key] = self::value($raw, $source, $number, $key);
            $definedAt[$key] = $number;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public static function parseFile(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new NoInputException("Cannot read the configuration file {$path}");
        }

        return self::parse($content, $path);
    }

    private static function value(string $raw, string $source, int $line, string $key): string
    {
        if ($raw === '') {
            return '';
        }

        if ($raw[0] === '\'') {
            $end = strpos($raw, '\'', 1);
            if ($end === false) {
                throw self::error($source, $line, "{$key} has an unterminated single quote");
            }
            self::assertOnlyComment(substr($raw, $end + 1), $source, $line, $key);

            return substr($raw, 1, $end - 1);
        }

        if ($raw[0] === '"') {
            $value = '';
            $length = strlen($raw);
            for ($i = 1; $i < $length; $i++) {
                $char = $raw[$i];
                if ($char === '\\' && $i + 1 < $length) {
                    $next = $raw[++$i];
                    $value .= match ($next) {
                        'n' => "\n",
                        't' => "\t",
                        '\\', '"', '$' => $next,
                        default => '\\' . $next,
                    };
                } elseif ($char === '"') {
                    self::assertOnlyComment(substr($raw, $i + 1), $source, $line, $key);

                    return $value;
                } else {
                    $value .= $char;
                }
            }

            throw self::error($source, $line, "{$key} has an unterminated double quote");
        }

        $comment = preg_match('/\s#/', $raw, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : null;

        return trim($comment === null ? $raw : substr($raw, 0, $comment));
    }

    private static function assertOnlyComment(string $rest, string $source, int $line, string $key): void
    {
        $rest = trim($rest);
        if ($rest !== '' && !str_starts_with($rest, '#')) {
            throw self::error($source, $line, "unexpected text after the quoted value of {$key}");
        }
    }

    private static function error(string $source, int $line, string $problem): ConfigException
    {
        return new ConfigException("Cannot parse the configuration file {$source}, line {$line}: {$problem}");
    }
}
