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
     * "device:inode" of stderr, as systemd writes it in $JOURNAL_STREAM; null when unknown.
     */
    public function stderrIdentity(): ?string;

    /**
     * @return array<string, string>
     */
    public function environment(): array;
}
