<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * The repository is locked by another ArkHive run or another restic client (exit 75, EX_TEMPFAIL):
 * nothing was done, so the operation can simply be tried again later.
 */
final class LockedException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::TEMPORARY, $hint, $previous);
        $this->transient = true;
    }
}
