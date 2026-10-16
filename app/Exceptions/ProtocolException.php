<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * A remote service answered something that does not follow its protocol (exit 76, EX_PROTOCOL).
 */
final class ProtocolException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::PROTOCOL, $hint, $previous);
    }
}
