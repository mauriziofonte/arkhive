<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * An output refused or impossible to create: target not empty, not enough space, an existing
 * database (exit 73, EX_CANTCREAT).
 */
final class RefusedException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::CANNOT_CREATE, $hint, $previous);
    }
}
