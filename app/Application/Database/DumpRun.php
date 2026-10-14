<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Domain\Backup\DumpResult;

/**
 * The dumps written for one backup, and the cleanup task that empties the dump directory.
 */
final readonly class DumpRun
{
    /**
     * @param list<DumpResult> $results
     */
    public function __construct(
        public DumpStaging $staging,
        public array $results,
        public int $cleanupId,
    ) {
    }

    public function totalBytes(): int
    {
        return array_sum(array_map(static fn (DumpResult $result): int => $result->bytes, $this->results));
    }
}
