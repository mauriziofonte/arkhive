<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Execution;

/**
 * How ArkHive is running, decided once at startup (spec §11).
 */
final readonly class ExecutionContext
{
    public function __construct(
        public bool $interactive,
        public bool $systemd,
        public bool $colour,
        public LogFormat $logFormat,
        /** the signal that decided the mode, for doctor and debug output */
        public string $reason,
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            '%s (%s), log format %s%s',
            $this->interactive ? 'interactive' : 'unattended',
            $this->reason,
            $this->logFormat->value,
            $this->systemd ? ', under systemd' : '',
        );
    }
}
