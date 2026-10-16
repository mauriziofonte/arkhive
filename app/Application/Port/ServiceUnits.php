<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Schedule\UnitState;
use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * The service manager's units: how they last ended, what they logged, and installing them.
 */
interface ServiceUnits
{
    /**
     * `systemctl show` of several units.
     *
     * @param list<string> $units
     * @param list<string> $names properties to read
     *
     * @return array<string, array<string, string>> unit => property => value
     *
     * @throws ArkhiveException systemctl is missing or fails
     */
    public function properties(array $units, array $names): array;

    /**
     * `systemctl daemon-reload`, after unit files changed.
     *
     * @throws ArkhiveException
     */
    public function reload(): void;

    /**
     * `systemctl enable --now`.
     *
     * @param list<string> $units
     *
     * @throws ArkhiveException
     */
    public function enableNow(array $units): void;

    /**
     * @throws ArkhiveException systemctl is missing or does not know the unit
     */
    public function state(string $unit): UnitState;

    /**
     * The last $lines lines one invocation of a unit logged, oldest first; [] when the journal cannot be read.
     *
     * @return list<string>
     */
    public function journal(string $invocationId, int $lines): array;
}
