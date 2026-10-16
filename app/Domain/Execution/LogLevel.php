<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Execution;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Success = 'success';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * syslog priority used by journald
     */
    public function priority(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 4,
            self::Notice => 5,
            self::Info, self::Success => 6,
            self::Debug => 7,
        };
    }
}
