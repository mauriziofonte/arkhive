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

    /**
     * Exit 75 as well, but never tried again within the run: a process stopped by a signal from
     * outside ArkHive (whoever sent it meant it; an out-of-memory kill would only happen again).
     */
    public static function notRetried(string $message, ?string $hint = null): self
    {
        $exception = new self($message, $hint);
        $exception->transient = false;

        return $exception;
    }
}
