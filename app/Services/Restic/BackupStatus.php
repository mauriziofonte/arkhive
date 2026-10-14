<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

use Mfonte\Arkhive\Support\Bytes;

/**
 * A "status" message of restic backup --json (emitted when RESTIC_PROGRESS_FPS is set).
 */
final readonly class BackupStatus
{
    public function __construct(
        public float $percentDone,
        public int $filesDone,
        public int $totalFiles,
        public int $bytesDone,
        public int $totalBytes,
        public ?int $secondsRemaining,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        return new self(
            ResticJson::float($data, 'percent_done'),
            ResticJson::int($data, 'files_done'),
            ResticJson::int($data, 'total_files'),
            ResticJson::int($data, 'bytes_done'),
            ResticJson::int($data, 'total_bytes'),
            array_key_exists('seconds_remaining', $data) ? ResticJson::int($data, 'seconds_remaining') : null,
        );
    }

    public function describe(): string
    {
        $line = sprintf(
            '%5.1f%%  %d/%d files  %s/%s',
            $this->percentDone * 100,
            $this->filesDone,
            $this->totalFiles,
            Bytes::format($this->bytesDone),
            Bytes::format($this->totalBytes),
        );
        if ($this->secondsRemaining !== null) {
            $line .= sprintf('  ETA %d:%02d', intdiv($this->secondsRemaining, 60), $this->secondsRemaining % 60);
        }

        return $line;
    }
}
