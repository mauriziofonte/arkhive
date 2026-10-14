<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Service;

use Closure;
use Mfonte\Arkhive\Application\Port\ServiceManager;

/**
 * What the service manager is told while a command runs: the current step as STATUS= (shown by
 * `systemctl status`), more time while progress is made (EXTEND_TIMEOUT_USEC=, so a long backup
 * that still advances is not stopped by TimeoutStartSec), watchdog pings, and the outcome.
 */
final class ServiceStatus
{
    /** seconds more given each time progress is reported: a run stalled this long past its timeout is stopped */
    public const EXTEND = 1800;

    /** at most one progress STATUS= per this many seconds */
    public const PROGRESS_EVERY = 2.0;

    /** longest STATUS= text */
    private const MAX_STATUS = 200;

    private ?float $lastProgress = null;
    private ?float $lastPing = null;

    /** @var Closure(): float seconds on a monotonic clock */
    private readonly Closure $now;

    /**
     * @param (Closure(): float)|null $now seconds on a monotonic clock (tests pass their own)
     */
    public function __construct(private readonly ServiceManager $manager, ?Closure $now = null)
    {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function supervised(): bool
    {
        return $this->manager->supervised();
    }

    /**
     * The command has started: READY=1 (meaningful for Type=notify units) with its first status.
     */
    public function started(string $status): void
    {
        $this->send(['READY' => '1', 'STATUS' => self::text($status)]);
    }

    /**
     * A new step ("Dumping MySQL database shop").
     */
    public function step(string $status): void
    {
        $this->send(['STATUS' => self::text($status)]);
    }

    /**
     * Progress within a step ("Backing up: 42.0% 120/300 files 1.2 GiB/3.1 GiB"): the status at a
     * limited rate, and more time.
     */
    public function progress(string $status): void
    {
        if (!$this->manager->supervised()) {
            return;
        }
        $now = ($this->now)();
        if ($this->lastProgress !== null && $now - $this->lastProgress < self::PROGRESS_EVERY) {
            $this->heartbeat();

            return;
        }
        $this->lastProgress = $now;
        $this->send(['STATUS' => self::text($status), 'EXTEND_TIMEOUT_USEC' => (string) (self::EXTEND * 1_000_000)]);
    }

    /**
     * A watchdog ping when one is due; cheap, called often (between the reads of a child process).
     */
    public function heartbeat(): void
    {
        $interval = $this->manager->watchdogInterval();
        if ($interval === null) {
            return;
        }
        $now = ($this->now)();
        if ($this->lastPing !== null && $now - $this->lastPing < $interval) {
            return;
        }
        $this->lastPing = $now;
        $this->manager->notify(['WATCHDOG' => '1']);
    }

    /**
     * The command has ended with $exit: the final status and STOPPING=1.
     */
    public function finished(int $exit, string $status): void
    {
        $this->send(['STOPPING' => '1', 'STATUS' => self::text($status), 'EXIT_STATUS' => (string) $exit]);
    }

    /**
     * @param array<string, string> $state
     */
    private function send(array $state): void
    {
        if (!$this->manager->supervised()) {
            return;
        }
        if ($this->manager->watchdogInterval() !== null) {
            $state['WATCHDOG'] = '1';
            $this->lastPing = ($this->now)();
        }
        $this->manager->notify($state);
    }

    private static function text(string $status): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $status));

        return mb_strlen($line) > self::MAX_STATUS ? mb_substr($line, 0, self::MAX_STATUS - 1) . '…' : $line;
    }
}
