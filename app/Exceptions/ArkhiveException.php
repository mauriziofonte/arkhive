<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;
use RuntimeException;
use Throwable;

/**
 * An expected failure: reported as a single error line (plus an optional hint) with a stable exit code.
 */
class ArkhiveException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $exitCode = ExitCode::FAILURE,
        private readonly ?string $hint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }
}
