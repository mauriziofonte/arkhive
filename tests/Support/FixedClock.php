<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Mfonte\Arkhive\Domain\Shared\Clock;

final class FixedClock implements Clock
{
    public function __construct(public DateTimeImmutable $now = new DateTimeImmutable('2026-10-12T10:20:30+02:00'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
