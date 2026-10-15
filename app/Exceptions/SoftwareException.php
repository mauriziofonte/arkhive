<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * Internal error, or output of another program ArkHive cannot understand (exit 70, EX_SOFTWARE).
 */
final class SoftwareException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::SOFTWARE, $hint, $previous);
    }
}
