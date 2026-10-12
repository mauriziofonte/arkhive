<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * SIGINT or SIGTERM received: cleanup runs while this unwinds, then ArkHive exits 130 or 143.
 */
final class InterruptedException extends ArkhiveException
{
    /** signal numbers, defined here because the pcntl constants exist only with ext-pcntl */
    public const SIGINT = 2;
    public const SIGTERM = 15;

    public function __construct(private readonly int $signal)
    {
        $interrupt = $signal === self::SIGINT;
        parent::__construct(
            'Interrupted by ' . ($interrupt ? 'SIGINT' : 'SIGTERM') . ': cleaned up and released the lock',
            $interrupt ? ExitCode::SIGINT : ExitCode::SIGTERM,
        );
    }

    public function signal(): int
    {
        return $this->signal;
    }
}
