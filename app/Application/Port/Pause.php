<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Exceptions\InterruptedException;

interface Pause
{
    /**
     * Waits, but not through SIGINT or SIGTERM.
     *
     * @throws InterruptedException when a signal arrives during the wait
     */
    public function sleep(float $seconds): void;
}
