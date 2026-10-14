<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use DateTimeImmutable;

/**
 * A lock file in the repository (`restic cat lock <id>`). restic itself treats a lock as stale when it
 * is older than 30 minutes, or when it was made on this host by a process that no longer exists.
 */
final readonly class RepositoryLock
{
    public const STALE_AFTER = 1800;

    public function __construct(
        public string $id,
        public DateTimeImmutable $time,
        public bool $exclusive,
        public string $hostname,
        public string $username,
        public int $pid,
    ) {
    }

    /**
     * @param callable(int): bool $processExists for locks made on this host
     */
    public function isStale(DateTimeImmutable $now, string $thisHost, callable $processExists): bool
    {
        if ($now->getTimestamp() - $this->time->getTimestamp() > self::STALE_AFTER) {
            return true;
        }

        return $this->hostname === $thisHost && $this->pid > 0 && !$processExists($this->pid);
    }

    public function describe(): string
    {
        return sprintf(
            '%s lock held by %s@%s (PID %d) since %s',
            $this->exclusive ? 'an exclusive' : 'a',
            $this->username,
            $this->hostname,
            $this->pid,
            $this->time->format('Y-m-d H:i:s T'),
        );
    }
}
