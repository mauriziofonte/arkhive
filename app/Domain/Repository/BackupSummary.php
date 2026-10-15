<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use Mfonte\Arkhive\Domain\Shared\Bytes;

/**
 * The "summary" message of restic backup --json.
 */
final readonly class BackupSummary
{
    public function __construct(
        public string $snapshotId,
        public int $filesNew,
        public int $filesChanged,
        public int $filesUnmodified,
        public int $dirsNew,
        public int $dirsChanged,
        public int $dirsUnmodified,
        public int $dataAdded,
        public ?int $dataAddedPacked,
        public int $totalFilesProcessed,
        public int $totalBytesProcessed,
        public float $totalDuration,
    ) {
    }

    public function withSnapshotId(string $snapshotId): self
    {
        return new self(
            $snapshotId,
            $this->filesNew,
            $this->filesChanged,
            $this->filesUnmodified,
            $this->dirsNew,
            $this->dirsChanged,
            $this->dirsUnmodified,
            $this->dataAdded,
            $this->dataAddedPacked,
            $this->totalFilesProcessed,
            $this->totalBytesProcessed,
            $this->totalDuration,
        );
    }

    public function shortId(): string
    {
        return substr($this->snapshotId, 0, 8);
    }

    public function describe(): string
    {
        return sprintf(
            'snapshot %s saved: %s added (%s processed), %d new, %d changed, %d unmodified files in %.1fs',
            $this->shortId(),
            Bytes::format($this->dataAdded),
            Bytes::format($this->totalBytesProcessed),
            $this->filesNew,
            $this->filesChanged,
            $this->filesUnmodified,
            $this->totalDuration,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'files_new' => $this->filesNew,
            'files_changed' => $this->filesChanged,
            'files_unmodified' => $this->filesUnmodified,
            'dirs_new' => $this->dirsNew,
            'dirs_changed' => $this->dirsChanged,
            'dirs_unmodified' => $this->dirsUnmodified,
            'data_added' => $this->dataAdded,
            'data_added_packed' => $this->dataAddedPacked,
            'total_files_processed' => $this->totalFilesProcessed,
            'total_bytes_processed' => $this->totalBytesProcessed,
            'total_duration' => $this->totalDuration,
        ];
    }
}
