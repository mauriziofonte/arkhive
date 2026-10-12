<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Dump;

final readonly class DumpResult
{
    public function __construct(
        public string $engine,
        public string $database,
        public string $path,
        public int $bytes,
        public float $duration,
    ) {
    }
}
