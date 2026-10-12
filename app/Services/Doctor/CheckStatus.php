<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Doctor;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Skipped = 'skipped';
    case Warning = 'warning';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Info => 'info',
            self::Skipped => 'skip',
            self::Warning => 'WARN',
            self::Failed => 'FAIL',
        };
    }
}
