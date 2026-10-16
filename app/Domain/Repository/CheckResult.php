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
     * @param list<string> $brokenPacks  pack ids (restic >= 0.17)
     * @param list<string> $missingPacks pack files the index lists but the repository no longer has
     * @param bool         $dataMissing  trees, blobs or packs are missing (repair index, not repair packs)
     */
    public function __construct(public bool $ok, public array $errors, public array $brokenPacks, public array $missingPacks = [], public bool $dataMissing = false)
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
        if ($this->missingPacks !== []) {
            $shown = array_map(static fn (string $id): string => substr($id, 0, 8), array_slice($this->missingPacks, 0, $max));
            $more = count($this->missingPacks) - count($shown);

            return sprintf(
                '%d pack file(s) listed in the index are missing from the repository (%s%s): the snapshots that use them cannot be restored in full',
                count($this->missingPacks),
                implode(', ', $shown),
                $more > 0 ? ", and {$more} more" : '',
            );
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
