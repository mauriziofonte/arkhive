<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use Mfonte\Arkhive\Domain\Shared\Bytes;

/**
 * A "status" or "summary" message of `restic restore --json` (status lines need RESTIC_PROGRESS_FPS).
 */
final readonly class RestoreProgress
{
    public function __construct(
        public float $percentDone,
        public int $totalFiles,
        public int $filesRestored,
        public int $totalBytes,
        public int $bytesRestored,
    ) {
    }

    public function describe(): string
    {
        $files = $this->filesRestored > 0 ? sprintf('%d/%d files  ', $this->filesRestored, $this->totalFiles) : sprintf('%d files  ', $this->totalFiles);

        return sprintf('%5.1f%%  %s%s/%s', $this->percentDone * 100, $files, Bytes::format($this->bytesRestored), Bytes::format($this->totalBytes));
    }
}
