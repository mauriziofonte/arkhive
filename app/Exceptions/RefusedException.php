<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * An operation refused for safety (exit 4).
 */
final class RefusedException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null)
    {
        parent::__construct($message, ExitCode::REFUSED, $hint);
    }
}
