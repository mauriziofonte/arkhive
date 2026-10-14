<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\Terminal;

final class FakeTerminal implements Terminal
{
    /**
     * @param array<string, string> $env
     */
    public function __construct(
        public bool $stdinTty = false,
        public bool $stdoutTty = false,
        public array $env = [],
    ) {
    }

    public function stdinIsTty(): bool
    {
        return $this->stdinTty;
    }

    public function stdoutIsTty(): bool
    {
        return $this->stdoutTty;
    }

    public function environment(): array
    {
        return $this->env;
    }
}
