<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Backup;

use Mfonte\Arkhive\Domain\Repository\BackupSummary;

final readonly class BackupReport
{
    /**
     * @param list<DumpResult> $dumps
     * @param list<string>     $warnings
     */
    public function __construct(
        public BackupSummary $summary,
        public array $dumps,
        public string $resticVersion,
        public array $warnings,
        public float $duration,
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
