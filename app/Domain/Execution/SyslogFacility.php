<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Execution;

/**
 * The syslog facilities ArkHive may log with (SYSLOG_FACILITY), with their RFC 5424 codes.
 */
enum SyslogFacility: string
{
    case User = 'user';
    case Daemon = 'daemon';
    case Local0 = 'local0';
    case Local1 = 'local1';
    case Local2 = 'local2';
    case Local3 = 'local3';
    case Local4 = 'local4';
    case Local5 = 'local5';
    case Local6 = 'local6';
    case Local7 = 'local7';

    public function code(): int
    {
        return match ($this) {
            self::User => 1,
            self::Daemon => 3,
            default => 16 + (int) substr($this->value, 5),
        };
    }

    /**
     * The PRI of a message: facility * 8 + severity.
     */
    public function priority(LogLevel $level): int
    {
        return $this->code() * 8 + $level->priority();
    }
}
