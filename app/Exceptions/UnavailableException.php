<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * A service or dependency is unavailable: repository, binary, database server (exit 69, EX_UNAVAILABLE).
 */
final class UnavailableException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::UNAVAILABLE, $hint, $previous);
    }

    /**
     * Unreachable over the network (connection refused or lost, name resolution): worth retrying.
     */
    public static function network(string $message, ?string $hint = null, ?\Throwable $previous = null): self
    {
        $exception = new self($message, $hint, $previous);
        $exception->transient = true;

        return $exception;
    }
}
