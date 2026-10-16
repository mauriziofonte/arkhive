<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Backup;

/**
 * How long one database dump may run (DUMP_TIMEOUT) and how long it may go without writing a byte
 * (DUMP_STALL_TIMEOUT), in seconds; null: no limit. A database server that stops answering (paused,
 * frozen storage, a lock that is never released) then fails that dump instead of holding the whole
 * backup until it comes back; the files are backed up as for any failed dump.
 */
final readonly class DumpLimits
{
    public const DEFAULT_STALL_TIMEOUT = 300;

    public function __construct(
        public ?int $timeout = null,
        public ?int $stallTimeout = self::DEFAULT_STALL_TIMEOUT,
    ) {
        if (($timeout !== null && $timeout < 1) || ($stallTimeout !== null && $stallTimeout < 1)) {
            throw new \InvalidArgumentException('A dump time limit is a positive number of seconds, or none');
        }
    }

    /**
     * From the configuration values, where 0 means no limit.
     */
    public static function fromSeconds(int $timeout, int $stallTimeout): self
    {
        return new self($timeout === 0 ? null : $timeout, $stallTimeout === 0 ? null : $stallTimeout);
    }
}
