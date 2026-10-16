<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\ProcessTable;
use Mfonte\Arkhive\Domain\System\ProcessState;

/**
 * Processes by PID: any PID not listed is gone.
 */
final class FakeProcessTable implements ProcessTable
{
    /**
     * @param array<int, ProcessState> $states
     * @param array<int, int>          $parents
     */
    public function __construct(public array $states = [], public array $parents = [])
    {
    }

    public function state(int $pid): ProcessState
    {
        return $this->states[$pid] ?? ProcessState::Gone;
    }

    public function parent(int $pid): ?int
    {
        return $this->parents[$pid] ?? null;
    }
}
