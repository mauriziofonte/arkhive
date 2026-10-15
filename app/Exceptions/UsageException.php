<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * Bad option or argument, or a required answer missing in unattended mode (exit 64, EX_USAGE).
 */
class UsageException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, ExitCode::USAGE, $hint, $previous);
    }

    /**
     * A required answer is missing and ArkHive must not prompt.
     */
    public static function missingFlag(string $flag, string $what): self
    {
        return new self("{$what} is required in unattended mode: pass {$flag}");
    }
}
