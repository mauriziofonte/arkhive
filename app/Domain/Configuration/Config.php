<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Configuration;

use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;

/**
 * A validated 2.0 configuration.
 */
final readonly class Config
{
    /**
     * @param list<string> $backupPaths
     * @param list<string> $exclusionPatterns 1.x syntax, as written in the file
     * @param list<string> $resticExcludes    converted restic patterns
     * @param list<string> $unknownKeys
     * @param bool         $dumpRequired a failed dump fails the run (DUMP_REQUIRED), instead of a warning
     * @param LogTarget|null $logTarget  LOG_TARGET; null: auto
     */
    public function __construct(
        public string $source,
        public array $backupPaths,
        public ResticSettings $restic,
        public int $keepDaily,
        public int $keepWeekly,
        public int $keepMonthly,
        public string $checkSubset,
        public ?string $dumpDirectory,
        public array $exclusionPatterns,
        public array $resticExcludes,
        public ?MysqlConfig $mysql,
        public ?PgsqlConfig $pgsql,
        public ?NotifyConfig $notify,
        public array $unknownKeys,
        public bool $dumpRequired = false,
        public ?LogTarget $logTarget = null,
        public SyslogFacility $syslogFacility = SyslogFacility::Daemon,
    ) {
    }

    public function hasDumps(): bool
    {
        return $this->mysql !== null || $this->pgsql !== null;
    }
}
