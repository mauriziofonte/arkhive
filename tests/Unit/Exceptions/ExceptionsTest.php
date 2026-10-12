<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionsTest extends TestCase
{
    public function testArkhiveExceptionDefaultsToFailure(): void
    {
        $previous = new RuntimeException('inner');
        $e = new ArkhiveException('broken', previous: $previous);

        self::assertSame(ExitCode::FAILURE, $e->exitCode());
        self::assertNull($e->hint());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testUsageAndRefusedCarryTheirExitCodesAndHints(): void
    {
        $usage = new UsageException('bad flag', 'see --help');
        $refused = new RefusedException('target not empty', 'pass --force');

        self::assertSame(ExitCode::USAGE, $usage->exitCode());
        self::assertSame('see --help', $usage->hint());
        self::assertSame(ExitCode::REFUSED, $refused->exitCode());
        self::assertSame('pass --force', $refused->hint());
    }

    public function testMissingFlagNamesTheFlag(): void
    {
        $e = UsageException::missingFlag('--snapshot <id|latest>', 'The snapshot');

        self::assertSame('The snapshot is required in unattended mode: pass --snapshot <id|latest>', $e->getMessage());
        self::assertSame(ExitCode::USAGE, $e->exitCode());
    }

    public function testInterruptionsMapTo130And143(): void
    {
        $int = new InterruptedException(InterruptedException::SIGINT);
        $term = new InterruptedException(InterruptedException::SIGTERM);

        self::assertSame(ExitCode::SIGINT, $int->exitCode());
        self::assertSame(2, $int->signal());
        self::assertSame('Interrupted by SIGINT: cleaned up and released the lock', $int->getMessage());
        self::assertSame(ExitCode::SIGTERM, $term->exitCode());
        self::assertSame('Interrupted by SIGTERM: cleaned up and released the lock', $term->getMessage());
    }

    public function testExitCodesAreStable(): void
    {
        self::assertSame(
            [0, 1, 2, 3, 4, 10, 130, 143],
            [ExitCode::SUCCESS, ExitCode::FAILURE, ExitCode::USAGE, ExitCode::WARNING, ExitCode::REFUSED, ExitCode::UPDATE_AVAILABLE, ExitCode::SIGINT, ExitCode::SIGTERM],
        );
    }
}
