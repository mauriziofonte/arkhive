<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\ServiceUnits;
use Mfonte\Arkhive\Domain\Schedule\UnitState;
use Mfonte\Arkhive\Exceptions\NoInputException;

/**
 * Units and journals as systemd would report them.
 */
final class FakeServiceUnits implements ServiceUnits
{
    /** @var array<string, UnitState> */
    public array $units = [];

    /** @var array<string, list<string>> invocation id => journal lines */
    public array $journals = [];

    public function state(string $unit): UnitState
    {
        return $this->units[$unit] ?? throw new NoInputException("systemd has no unit {$unit}");
    }

    public function journal(string $invocationId, int $lines): array
    {
        return array_slice($this->journals[$invocationId] ?? [], -$lines);
    }
}
