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

    /** @var array<string, array<string, string>> unit => `systemctl show` properties */
    public array $properties = [];

    /** @var list<string> what was asked of systemctl: "daemon-reload", "enable --now <units>" */
    public array $calls = [];

    public function properties(array $units, array $names): array
    {
        $result = [];
        foreach ($units as $unit) {
            $result[$unit] = array_intersect_key($this->properties[$unit] ?? ['LoadState' => 'not-found'], array_flip($names));
        }

        return $result;
    }

    public function reload(): void
    {
        $this->calls[] = 'daemon-reload';
    }

    public function enableNow(array $units): void
    {
        $this->calls[] = 'enable --now ' . implode(' ', $units);
    }

    public function state(string $unit): UnitState
    {
        return $this->units[$unit] ?? throw new NoInputException("systemd has no unit {$unit}");
    }

    public function journal(string $invocationId, int $lines): array
    {
        return array_slice($this->journals[$invocationId] ?? [], -$lines);
    }
}
