<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\System;

/**
 * What a PID of this host is now, for a lock that names it.
 */
enum ProcessState: string
{
    /** a process has this PID: it may be the one that made the lock */
    case Running = 'running';

    /** the process has exited, but its parent has not collected it (state Z in /proc/<pid>/stat) */
    case Zombie = 'zombie';

    /** no process has this PID */
    case Gone = 'gone';
}
