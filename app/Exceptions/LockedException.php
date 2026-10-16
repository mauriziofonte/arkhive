<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Closure;
use Mfonte\Arkhive\ExitCode;

/**
 * The repository is locked by another ArkHive run or another restic client (exit 75, EX_TEMPFAIL):
 * nothing was done, so the operation can simply be tried again later.
 */
final class LockedException extends ArkhiveException
{
    /** @var (Closure(): void)|null */
    private ?Closure $unlock = null;

    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::TEMPORARY, $hint, $previous);
        $this->transient = true;
    }

    /**
     * A lock nothing will release, its process being dead: exit 75 as well, but not waited for.
     * $unlock removes it, given only when the configuration lets ArkHive do so (UNLOCK_STALE).
     *
     * @param (Closure(): void)|null $unlock
     */
    public static function stale(string $message, string $hint, ?Closure $unlock = null): self
    {
        $exception = new self($message, $hint);
        $exception->transient = false;
        $exception->unlock = $unlock;

        return $exception;
    }

    /**
     * @return (Closure(): void)|null
     */
    public function unlock(): ?Closure
    {
        return $this->unlock;
    }
}
