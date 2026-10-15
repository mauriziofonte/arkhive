<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\System;

use DateTimeImmutable;
use Mfonte\Arkhive\Domain\Shared\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
