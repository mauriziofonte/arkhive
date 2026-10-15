<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use Mfonte\Arkhive\Domain\Shared\Bytes;

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
