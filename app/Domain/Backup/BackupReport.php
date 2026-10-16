<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Backup;

use Mfonte\Arkhive\Domain\Repository\BackupSummary;
use Mfonte\Arkhive\ExitCode;

/**
 * What a backup did: the snapshot, the dumps written and those that failed, and the warnings.
 */
final readonly class BackupReport
{
    /**
     * @param list<DumpResult>  $dumps
     * @param list<string>      $warnings
     * @param list<DumpFailure> $dumpFailures
     * @param bool              $dumpsRequired DUMP_REQUIRED: a failed dump fails the run
     */
    public function __construct(
        public BackupSummary $summary,
        public array $dumps,
        public string $resticVersion,
        public array $warnings,
        public float $duration,
        public array $dumpFailures = [],
        public bool $dumpsRequired = false,
    ) {
    }

    public function withWarning(string $warning): self
    {
        return new self($this->summary, $this->dumps, $this->resticVersion, [...$this->warnings, $warning], $this->duration, $this->dumpFailures, $this->dumpsRequired);
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * The snapshot was saved, but a dump that DUMP_REQUIRED asks for is missing from it.
     */
    public function failed(): bool
    {
        return $this->dumpsRequired && $this->dumpFailures !== [];
    }

    /**
     * 0, 3 with warnings, or the exit code of the first failed dump when the dumps are required.
     */
    public function exitCode(): int
    {
        if ($this->failed()) {
            return $this->dumpFailures[0]->exitCode;
        }

        return $this->hasWarnings() ? ExitCode::WARNING : ExitCode::SUCCESS;
    }
}
