<?php

declare(strict_types=1);

namespace Tests\Support;

final readonly class CliResult
{
    public function __construct(public int $exitCode, public string $stdout, public string $stderr)
    {
    }

    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }
}
