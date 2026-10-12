<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Backup;

use Mfonte\Arkhive\Services\Dump\DumpResult;
use Mfonte\Arkhive\Services\Restic\BackupSummary;

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
