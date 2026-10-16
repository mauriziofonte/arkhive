<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * Data verified and found wrong: damaged archive, checksum mismatch, failed check (exit 65, EX_DATAERR).
 */
final class DataException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::DATA_ERROR, $hint, $previous);
    }
}
