<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * The operating system refused something ArkHive needs, such as starting a process (exit 71, EX_OSERR).
 */
final class OsException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::OS_ERROR, $hint, $previous);
    }
}
