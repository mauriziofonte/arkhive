<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * A temporary failure: a lock held by another run, a timeout. Retrying later may succeed (exit 75, EX_TEMPFAIL).
 */
final class TemporaryException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::TEMPORARY, $hint, $previous);
        $this->transient = true;
    }
}
