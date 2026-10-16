<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Output;

/**
 * Plain aligned columns (no box drawing), readable in a terminal, a log and an e-mail alike.
 */
final class TextTable
{
    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     * @param list<int>          $rightAligned column indexes aligned to the right (numbers, sizes)
     */
    public static function render(array $headers, array $rows, array $rightAligned = []): string
    {
        $widths = array_map(self::width(...), $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, self::width($cell));
            }
        }
        $lines = [self::line($headers, $widths, $rightAligned)];
        foreach ($rows as $row) {
            $lines[] = self::line($row, $widths, $rightAligned);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     * @param list<int> $rightAligned
     */
    private static function line(array $cells, array $widths, array $rightAligned): string
    {
        $out = [];
        $last = count($widths) - 1;
        foreach ($widths as $i => $width) {
            $cell = $cells[$i] ?? '';
            $pad = str_repeat(' ', max(0, $width - self::width($cell)));
            if (in_array($i, $rightAligned, true)) {
                $out[] = $pad . $cell;
            } else {
                $out[] = $i === $last ? $cell : $cell . $pad;
            }
        }

        return rtrim(implode('  ', $out));
    }

    private static function width(string $text): int
    {
        return mb_strwidth($text, 'UTF-8');
    }
}
