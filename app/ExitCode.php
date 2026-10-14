<?php

declare(strict_types=1);

namespace Mfonte\Arkhive;

/**
 * Stable, documented exit codes (spec §6 and decision 4).
 */
final class ExitCode
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    /** configuration or usage error, including a required answer missing in unattended mode */
    public const USAGE = 2;
    /** completed with warnings, including a notification failure */
    public const WARNING = 3;
    /** refused for safety: non-empty target, insufficient space, in-place without confirmation */
    public const REFUSED = 4;
    /** self-update --check: an update is available */
    public const UPDATE_AVAILABLE = 10;
    public const SIGINT = 130;
    public const SIGTERM = 143;

    private function __construct()
    {
    }
}
