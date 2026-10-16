<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\System\ProcessState;

/**
 * The processes of this host.
 */
interface ProcessTable
{
    /**
     * A PID that cannot be looked up (0 or negative, or hidden) counts as running.
     */
    public function state(int $pid): ProcessState;

    /**
     * The parent PID of a process, null when it is gone or cannot be read.
     */
    public function parent(int $pid): ?int;
}
