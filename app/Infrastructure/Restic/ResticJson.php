<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Restic;

/**
 * restic --json output: one JSON object per line. Anything else on the line is not restic JSON
 * (older versions print some messages as text) and is returned as null.
 */
final class ResticJson
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || $line[0] !== '{') {
            return null;
        }
        $data = json_decode($line, true);
        if (!is_array($data)) {
            return null;
        }
        $object = [];
        foreach ($data as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) || is_float($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? 0;

        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
