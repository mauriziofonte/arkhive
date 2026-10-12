<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Maintain;

use Mfonte\Arkhive\Services\Restic\CheckResult;
use Mfonte\Arkhive\Services\Restic\ForgetResult;
use Mfonte\Arkhive\Services\Restic\RepositoryStats;

final readonly class MaintainReport
{
    public function __construct(
        public ForgetResult $forget,
        public CheckResult $structure,
        public ?CheckResult $data,
        public string $subset,
        public ?RepositoryStats $stats,
        public string $resticVersion,
        public float $duration,
    ) {
    }

    public function healthy(): bool
    {
        return $this->structure->ok && $this->data !== null && $this->data->ok;
    }

    /**
     * Broken pack ids from both checks.
     *
     * @return list<string>
     */
    public function brokenPacks(): array
    {
        return array_values(array_unique([...$this->structure->brokenPacks, ...($this->data->brokenPacks ?? [])]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy(),
            'snapshots_kept' => count($this->forget->kept),
            'snapshots_removed' => $this->forget->removed,
            'check' => ['ok' => $this->structure->ok, 'errors' => $this->structure->errors],
            'read_data' => $this->data === null ? null : ['subset' => $this->subset, 'ok' => $this->data->ok, 'errors' => $this->data->errors],
            'broken_packs' => $this->brokenPacks(),
            'raw_size' => $this->stats?->rawBytes,
            'restic_version' => $this->resticVersion,
            'duration' => round($this->duration, 3),
        ];
    }
}
