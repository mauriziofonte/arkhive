<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Shared;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
