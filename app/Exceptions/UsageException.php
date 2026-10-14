<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Exceptions;

use Mfonte\Arkhive\ExitCode;

/**
 * Configuration or usage error (exit 2).
 */
class UsageException extends ArkhiveException
{
    public function __construct(string $message, ?string $hint = null)
    {
        parent::__construct($message, ExitCode::USAGE, $hint);
    }

    /**
     * A required answer is missing and ArkHive must not prompt.
     */
    public static function missingFlag(string $flag, string $what): self
    {
        return new self("{$what} is required in unattended mode: pass {$flag}");
    }
}
