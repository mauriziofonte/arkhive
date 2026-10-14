<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Configuration;

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
    ) {
    }

    public function hasDumps(): bool
    {
        return $this->mysql !== null || $this->pgsql !== null;
    }
}
