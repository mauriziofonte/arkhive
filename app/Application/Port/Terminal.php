<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

/**
 * The process's view of its standard streams and environment; swapped in tests.
 */
interface Terminal
{
    public function stdinIsTty(): bool;

    public function stdoutIsTty(): bool;

    /**
     * @return array<string, string>
     */
    public function environment(): array;
}
