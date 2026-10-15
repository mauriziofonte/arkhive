<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * An input does not exist: file, snapshot, path in a snapshot, release (exit 66, EX_NOINPUT).
 */
final class NoInputException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::NO_INPUT, $hint, $previous);
    }
}
