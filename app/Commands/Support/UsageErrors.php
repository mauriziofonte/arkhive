<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands\Support;

use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;

/**
 * A usage error found before a command runs (unknown command or option, missing argument): one
 * plain log line and its hint on stderr, exit 64 (EX_USAGE), as for every other usage error.
 */
final class UsageErrors
{
    public static function report(string $message, ?string $hint = null): int
    {
        $log = new Logger(
            new ExecutionContext(false, false, false, LogFormat::Plain, 'usage error'),
            app(LogStream::class)->resource(),
            null,
            app(Clock::class),
        );
        $log->error($message);
        if ($hint !== null) {
            $log->notice($hint);
        }

        return ExitCode::USAGE;
    }
}
