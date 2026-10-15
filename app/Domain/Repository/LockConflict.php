<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * The lock restic named when it refused to run: "repository is already locked [exclusively] by PID
 * 25983 on host.example.com by root (UID 0, GID 0)", the same text from restic 0.11 to 0.19. The
 * hostname is the one of the machine where the lock was made (not RESTIC_HOST).
 */
final readonly class LockConflict
{
    public function __construct(
        public int $pid,
        public string $hostname,
        public string $username,
        public bool $exclusive,
    ) {
    }

    /**
     * From restic's error text (JSON records already decoded), or null when it names no lock.
     */
    public static function parse(string $text): ?self
    {
        if (preg_match('/already locked (exclusively )?by PID (\d+) on (\S+) by (.*?) \(UID -?\d+, GID -?\d+\)/', $text, $match) !== 1) {
            return null;
        }

        return new self((int) $match[2], $match[3], $match[4], $match[1] !== '');
    }

    /**
     * Made on the host named $hostname, by a process whose PID can be looked up there.
     */
    public function madeOn(string $hostname): bool
    {
        return $this->pid > 0 && $this->hostname === $hostname;
    }
}
