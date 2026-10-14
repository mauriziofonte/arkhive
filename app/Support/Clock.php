<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
