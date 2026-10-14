<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\CancelledException;
use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\OsException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\ProtocolException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(ExitCode::CANNOT_CREATE, $refused->exitCode());
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

    public function testExitCodesAreStableAndFollowSysexits(): void
    {
        self::assertSame(
            [0, 1, 3, 10, 64, 65, 66, 69, 70, 71, 73, 74, 75, 76, 77, 78, 130, 143],
            [
                ExitCode::SUCCESS, ExitCode::FAILURE, ExitCode::WARNING, ExitCode::UPDATE_AVAILABLE,
                ExitCode::USAGE, ExitCode::DATA_ERROR, ExitCode::NO_INPUT, ExitCode::UNAVAILABLE, ExitCode::SOFTWARE,
                ExitCode::OS_ERROR, ExitCode::CANNOT_CREATE, ExitCode::IO_ERROR, ExitCode::TEMPORARY, ExitCode::PROTOCOL,
                ExitCode::NO_PERMISSION, ExitCode::CONFIG, ExitCode::SIGINT, ExitCode::SIGTERM,
            ],
        );
        self::assertSame([0, 1, 3, 10, 64, 65, 66, 69, 70, 71, 73, 74, 75, 76, 77, 78, 130, 143], array_keys(ExitCode::TABLE));
        self::assertSame(['USAGE', 'DATAERR', 'NOINPUT', 'NOPERM', 'CONFIG'], array_map(static fn (int $code): string => ExitCode::TABLE[$code][0], ExitCode::NOT_RETRYABLE));
    }

    /**
     * @return iterable<string, array{class-string<ArkhiveException>, int}>
     */
    public static function classes(): iterable
    {
        yield 'usage' => [UsageException::class, ExitCode::USAGE];
        yield 'data' => [DataException::class, ExitCode::DATA_ERROR];
        yield 'no input' => [NoInputException::class, ExitCode::NO_INPUT];
        yield 'unavailable' => [UnavailableException::class, ExitCode::UNAVAILABLE];
        yield 'software' => [SoftwareException::class, ExitCode::SOFTWARE];
        yield 'os' => [OsException::class, ExitCode::OS_ERROR];
        yield 'refused' => [RefusedException::class, ExitCode::CANNOT_CREATE];
        yield 'io' => [IoException::class, ExitCode::IO_ERROR];
        yield 'temporary' => [TemporaryException::class, ExitCode::TEMPORARY];
        yield 'protocol' => [ProtocolException::class, ExitCode::PROTOCOL];
        yield 'permission' => [PermissionException::class, ExitCode::NO_PERMISSION];
        yield 'config' => [ConfigException::class, ExitCode::CONFIG];
        yield 'cancelled' => [CancelledException::class, ExitCode::FAILURE];
    }

    /**
     * @param class-string<ArkhiveException> $class
     */
    #[DataProvider('classes')]
    public function testEveryFailureClassCarriesItsExitCodeHintAndCause(string $class, int $code): void
    {
        $previous = new \RuntimeException('cause');
        $e = new $class('what failed', 'how to fix it', $previous);

        self::assertSame($code, $e->exitCode());
        self::assertSame('what failed', $e->getMessage());
        self::assertSame('how to fix it', $e->hint());
        self::assertSame($previous, $e->getPrevious());
        self::assertNull((new $class('no hint'))->hint());
    }
}
