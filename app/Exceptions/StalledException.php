<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * A child process stopped for making no progress (Command::$stallTimeout): the server it talks to
 * stopped answering. Exit 75, EX_TEMPFAIL, like a timeout.
 */
final class StalledException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null)
    {
        parent::__construct($message, ExitCode::TEMPORARY, $hint);
        $this->transient = true;
    }
}
