<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;

/**
 * Opens the event log of a log target.
 */
interface EventLogs
{
    /**
     * @return EventLog|null null for LogTarget::Stderr
     */
    public function open(LogTarget $target, SyslogFacility $facility): ?EventLog;
}
