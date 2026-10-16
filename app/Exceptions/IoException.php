<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * A local read or write failed, typically a full filesystem (exit 74, EX_IOERR).
 */
final class IoException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::IO_ERROR, $hint, $previous);
    }
}
