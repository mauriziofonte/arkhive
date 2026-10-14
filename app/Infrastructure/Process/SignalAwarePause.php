<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Process;

use Mfonte\Arkhive\Application\Port\Pause;

/**
 * Sleeps in short slices and stops at once when SIGINT or SIGTERM arrives.
 */
final class SignalAwarePause implements Pause
{
    private const SLICE = 0.1;

    public function __construct(private readonly Signals $signals)
    {
    }

    public function sleep(float $seconds): void
    {
        $deadline = hrtime(true) + (int) ($seconds * 1e9);
        $this->signals->check();
        while (($left = $deadline - hrtime(true)) > 0) {
            usleep((int) (min(self::SLICE, $left / 1e9) * 1e6));
            $this->signals->check();
        }
    }
}
