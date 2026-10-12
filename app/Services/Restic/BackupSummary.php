<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

use Mfonte\Arkhive\Support\Bytes;

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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        return new self(
            ResticJson::string($data, 'snapshot_id'),
            ResticJson::int($data, 'files_new'),
            ResticJson::int($data, 'files_changed'),
            ResticJson::int($data, 'files_unmodified'),
            ResticJson::int($data, 'dirs_new'),
            ResticJson::int($data, 'dirs_changed'),
            ResticJson::int($data, 'dirs_unmodified'),
            ResticJson::int($data, 'data_added'),
            array_key_exists('data_added_packed', $data) ? ResticJson::int($data, 'data_added_packed') : null,
            ResticJson::int($data, 'total_files_processed'),
            ResticJson::int($data, 'total_bytes_processed'),
            ResticJson::float($data, 'total_duration'),
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
