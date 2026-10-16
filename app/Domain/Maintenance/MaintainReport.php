<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Maintenance;

use Mfonte\Arkhive\Domain\Repository\CheckResult;
use Mfonte\Arkhive\Domain\Repository\ForgetResult;
use Mfonte\Arkhive\Domain\Repository\RepositoryStats;

final readonly class MaintainReport
{
    /** why forget did not run on a repository the checks found damaged */
    public const RETENTION_SKIPPED = 'the repository check found errors, so no snapshot was removed';

    public function __construct(
        /** what forget removed; null when it did not run (a failed check) or refused to run */
        public ?ForgetResult $forget,
        public CheckResult $structure,
        public ?CheckResult $data,
        public string $subset,
        public ?RepositoryStats $stats,
        public string $resticVersion,
        public float $duration,
        /** why forget or prune refused to run (missing data), when one did */
        public ?string $forgetError = null,
    ) {
    }

    public function healthy(): bool
    {
        return $this->forgetError === null && $this->structure->ok && $this->data !== null && $this->data->ok;
    }

    /**
     * Data the index lists is gone from the repository (repair index, not repair packs).
     */
    public function dataMissing(): bool
    {
        return $this->forgetError !== null || $this->structure->dataMissing || ($this->data !== null && $this->data->dataMissing);
    }

    /**
     * What is wrong, in a sentence: the first failed check, or why prune refused to run.
     */
    public function problem(): string
    {
        $failed = !$this->structure->ok || $this->data === null ? $this->structure : $this->data;
        if (!$failed->ok) {
            return 'The repository check found errors: ' . $failed->describe();
        }

        return (string) $this->forgetError;
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
     * Why the retention policy was not applied in full, or null when it was.
     */
    public function retentionError(): ?string
    {
        if ($this->forgetError !== null) {
            return $this->forgetError;
        }

        return $this->forget === null ? self::RETENTION_SKIPPED : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy(),
            'retention_applied' => $this->forget !== null,
            'snapshots_kept' => count($this->forget->kept ?? []),
            'snapshots_removed' => $this->forget->removed ?? [],
            'check' => ['ok' => $this->structure->ok, 'errors' => $this->structure->errors],
            'read_data' => $this->data === null ? null : ['subset' => $this->subset, 'ok' => $this->data->ok, 'errors' => $this->data->errors],
            'broken_packs' => $this->brokenPacks(),
            'missing_packs' => array_values(array_unique([...$this->structure->missingPacks, ...($this->data->missingPacks ?? [])])),
            'retention_error' => $this->retentionError(),
            'raw_size' => $this->stats?->rawBytes,
            'restic_version' => $this->resticVersion,
            'duration' => round($this->duration, 3),
        ];
    }
}
