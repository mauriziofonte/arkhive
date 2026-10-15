<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * The outcome of `restic check`. restic >= 0.17 reports a JSON summary and JSON error lines;
 * older versions print text, so the errors then come from the plain output.
 */
final readonly class CheckResult
{
    /**
     * @param list<string> $errors      what restic reported, in order
     * @param list<string> $brokenPacks pack ids (restic >= 0.17)
     */
    public function __construct(public bool $ok, public array $errors, public array $brokenPacks)
    {
    }

    /**
     * The first lines of the report, for a log line or a notification.
     */
    public function describe(int $max = 3): string
    {
        if ($this->ok) {
            return 'no errors were found';
        }
        $lines = [];
        foreach ($this->errors as $error) {
            $first = trim((string) strtok($error, "\n"));
            if ($first !== '' && !str_starts_with($first, 'Fatal: repository contains errors')) {
                $lines[] = $first;
            }
        }
        if ($lines === []) {
            return 'the repository contains errors';
        }
        $shown = array_slice($lines, 0, $max);
        $more = count($lines) - count($shown);

        return implode('; ', $shown) . ($more > 0 ? "; and {$more} more" : '');
    }
}
