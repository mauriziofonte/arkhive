<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Drill;

use Mfonte\Arkhive\Domain\Repository\Snapshot;

/**
 * The outcome of a restore drill: which files were restored and verified, and the dump test-import.
 */
final readonly class DrillReport
{
    /**
     * @param list<string>                                                                                     $files    sampled paths
     * @param list<string>                                                                                     $failures what went wrong, one line each
     * @param array{engine: string, database: string, imported_as: string, bytes: int, ok: bool, error: ?string}|null $database
     */
    public function __construct(
        public Snapshot $snapshot,
        public int $available,
        public array $files,
        public int $bytes,
        public array $failures,
        public ?array $database,
        public float $duration,
    ) {
    }

    public function passed(): bool
    {
        return $this->failures === [] && ($this->database === null || $this->database['ok']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'snapshot' => $this->snapshot->id,
            'snapshot_time' => $this->snapshot->time->format(DATE_ATOM),
            'host' => $this->snapshot->hostname,
            'files_available' => $this->available,
            'files_tested' => count($this->files),
            'bytes_tested' => $this->bytes,
            'failures' => $this->failures,
            'database' => $this->database,
            'duration' => round($this->duration, 3),
        ];
    }
}
