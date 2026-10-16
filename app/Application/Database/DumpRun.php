<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Domain\Backup\DumpFailure;
use Mfonte\Arkhive\Domain\Backup\DumpResult;

/**
 * The dumps written for one backup, those that failed, and the cleanup task that empties the dump
 * directory.
 */
final readonly class DumpRun
{
    /**
     * @param list<DumpResult>  $results
     * @param list<DumpFailure> $failures
     */
    public function __construct(
        public DumpStaging $staging,
        public array $results,
        public int $cleanupId,
        public array $failures = [],
    ) {
    }

    public function totalBytes(): int
    {
        return array_sum(array_map(static fn (DumpResult $result): int => $result->bytes, $this->results));
    }
}
