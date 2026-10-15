<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use DateTimeImmutable;

/**
 * One snapshot from `restic snapshots --json` (or the first line of `restic ls --json`).
 * The size (bytes processed by the backup) exists only for snapshots made by restic 0.17 or newer.
 */
final readonly class Snapshot
{
    /**
     * @param list<string> $paths
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $time,
        public string $hostname,
        public array $paths,
        public array $tags = [],
        public ?int $size = null,
        public string $username = '',
    ) {
    }

    public function shortId(): string
    {
        return substr($this->id, 0, 8);
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * Age in whole hours at $now (never negative, so a clock slightly behind the server reads 0).
     */
    public function ageHours(DateTimeImmutable $now): int
    {
        return max(0, intdiv($now->getTimestamp() - $this->time->getTimestamp(), 3600));
    }

    /**
     * "3 h", "2 d", "5 w": short enough for a table column.
     */
    public function age(DateTimeImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $this->time->getTimestamp());

        return match (true) {
            $seconds < 3600 => intdiv($seconds, 60) . ' min',
            $seconds < 2 * 86400 => intdiv($seconds, 3600) . ' h',
            $seconds < 14 * 86400 => intdiv($seconds, 86400) . ' d',
            default => intdiv($seconds, 7 * 86400) . ' w',
        };
    }
}
