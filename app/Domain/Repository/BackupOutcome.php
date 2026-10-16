<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * What a backup run produced: the snapshot summary (null when none was reported) and, for an
 * incomplete snapshot, the source items that could not be read.
 */
final readonly class BackupOutcome
{
    /**
     * @param list<string> $unreadable
     */
    public function __construct(
        public ?BackupSummary $summary,
        public bool $incomplete = false,
        public array $unreadable = [],
        public string $diagnostics = '',
    ) {
    }

    public function snapshotId(): ?string
    {
        return $this->summary === null || $this->summary->snapshotId === '' ? null : $this->summary->snapshotId;
    }
}
