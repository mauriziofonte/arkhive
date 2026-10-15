<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Execution\LogLevel;

/**
 * A log destination that keeps structured fields: the systemd journal, syslog.
 */
interface EventLog
{
    /**
     * @param array<string, string> $fields upper-case journal field names (ARKHIVE_COMMAND, MESSAGE_ID...)
     *
     * @return bool false when the event could not be delivered (the caller falls back to stderr)
     */
    public function write(LogLevel $level, string $message, array $fields): bool;
}
