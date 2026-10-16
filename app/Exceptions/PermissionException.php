<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * Permission denied, wrong password, or a secret readable by others (exit 77, EX_NOPERM).
 */
final class PermissionException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::NO_PERMISSION, $hint, $previous);
    }
}
