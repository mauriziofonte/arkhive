<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Schedule\UnitState;
use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * The service manager's view of a unit: how it last ended and what it logged.
 */
interface ServiceUnits
{
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
