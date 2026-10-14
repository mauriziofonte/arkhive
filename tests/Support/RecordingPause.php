<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Mfonte\Arkhive\Application\Port\Pause;

/**
 * A Pause that does not wait: it records each wait, and can run something during it (another run
 * releasing a lock, a signal arriving).
 */
final class RecordingPause implements Pause
{
    /** @var list<float> */
    public array $waits = [];

    /**
     * @param (Closure(int): void)|null $during called with the number of the wait (1 for the first)
     */
    public function __construct(public ?Closure $during = null)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
        if ($this->during !== null) {
            ($this->during)(count($this->waits));
        }
    }

    public function total(): float
    {
        return array_sum($this->waits);
    }
}
