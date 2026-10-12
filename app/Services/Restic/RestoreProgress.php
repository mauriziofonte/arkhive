<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

use Mfonte\Arkhive\Support\Bytes;

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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        $total = ResticJson::int($data, 'total_bytes');
        $restored = ResticJson::int($data, 'bytes_restored');
        $percent = array_key_exists('percent_done', $data)
            ? ResticJson::float($data, 'percent_done')
            : ($total > 0 ? $restored / $total : 1.0);

        return new self(
            $percent,
            ResticJson::int($data, 'total_files'),
            ResticJson::int($data, 'files_restored'),
            $total,
            $restored,
        );
    }

    public function describe(): string
    {
        $files = $this->filesRestored > 0 ? sprintf('%d/%d files  ', $this->filesRestored, $this->totalFiles) : sprintf('%d files  ', $this->totalFiles);

        return sprintf('%5.1f%%  %s%s/%s', $this->percentDone * 100, $files, Bytes::format($this->bytesRestored), Bytes::format($this->totalBytes));
    }
}
