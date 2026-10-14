<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * The operator declined a confirmation: nothing was changed (exit 1).
 */
final class CancelledException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::FAILURE, $hint, $previous);
    }
}
