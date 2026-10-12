<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Context;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Context\ContextDetector;
use Mfonte\Arkhive\Services\Context\ExecutionContext;
use Mfonte\Arkhive\Services\Context\LogFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextDetectorTest extends TestCase
{
    private const TERM = ['TERM' => 'xterm-256color'];

    /**
     * @return iterable<string, array{array<string, string>, bool, bool, bool, bool, bool, string, LogFormat, bool}>
     *         env, stdin tty, stdout tty, -n, --interactive, expected interactive, reason, format, colour
     */
    public static function signals(): iterable
    {
        yield 'terminal' => [self::TERM, true, true, false, false, true, 'terminal', LogFormat::Human, true];
        yield 'terminal without TERM' => [[], true, true, false, false, true, 'terminal', LogFormat::Human, true];
        yield 'TERM=dumb' => [['TERM' => 'dumb'], true, true, false, false, false, 'no terminal', LogFormat::Plain, false];
        yield 'no tty' => [self::TERM, false, false, false, false, false, 'no terminal', LogFormat::Plain, false];
        yield 'stdin piped' => [self::TERM, false, true, false, false, false, 'no terminal', LogFormat::Plain, false];
        yield 'stdout piped' => [self::TERM, true, false, false, false, false, 'no terminal', LogFormat::Plain, false];
        yield '-n at a terminal' => [self::TERM, true, true, true, false, false, '--no-interaction', LogFormat::Plain, false];
        yield 'ARKHIVE_NONINTERACTIVE=1' => [self::TERM + ['ARKHIVE_NONINTERACTIVE' => '1'], true, true, false, false, false, 'ARKHIVE_NONINTERACTIVE', LogFormat::Plain, false];
        yield 'ARKHIVE_NONINTERACTIVE=true' => [self::TERM + ['ARKHIVE_NONINTERACTIVE' => 'true'], true, true, false, false, false, 'ARKHIVE_NONINTERACTIVE', LogFormat::Plain, false];
        yield 'ARKHIVE_NONINTERACTIVE=0 is ignored' => [self::TERM + ['ARKHIVE_NONINTERACTIVE' => '0'], true, true, false, false, true, 'terminal', LogFormat::Human, true];
        yield 'INVOCATION_ID' => [self::TERM + ['INVOCATION_ID' => 'abc123'], true, true, false, false, false, 'systemd', LogFormat::Journald, false];
        yield 'JOURNAL_STREAM' => [['JOURNAL_STREAM' => '8:12345'], false, false, false, false, false, 'systemd', LogFormat::Journald, false];
        yield 'systemd and -n' => [['INVOCATION_ID' => 'abc'], false, false, true, false, false, '--no-interaction', LogFormat::Journald, false];
        yield 'CI' => [self::TERM + ['CI' => 'true'], true, true, false, false, false, 'CI', LogFormat::Plain, false];
        yield 'empty CI is ignored' => [self::TERM + ['CI' => ''], true, true, false, false, true, 'terminal', LogFormat::Human, true];
        yield 'NO_COLOR at a terminal' => [self::TERM + ['NO_COLOR' => '1'], true, true, false, false, true, 'terminal', LogFormat::Human, false];
        yield 'empty NO_COLOR keeps colour' => [self::TERM + ['NO_COLOR' => ''], true, true, false, false, true, 'terminal', LogFormat::Human, true];
        yield '--interactive with CI' => [self::TERM + ['CI' => '1'], true, true, false, true, true, '--interactive', LogFormat::Human, true];
        yield '--interactive under systemd' => [self::TERM + ['INVOCATION_ID' => 'x'], true, true, false, true, true, '--interactive', LogFormat::Human, true];
        yield '--interactive with stdout piped' => [self::TERM, true, false, false, true, true, '--interactive', LogFormat::Human, false];
        yield '--interactive with ARKHIVE_NONINTERACTIVE' => [self::TERM + ['ARKHIVE_NONINTERACTIVE' => '1'], true, true, false, true, true, '--interactive', LogFormat::Human, true];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('signals')]
    public function testSignalsMapToTheExpectedMode(
        array $env,
        bool $stdinTty,
        bool $stdoutTty,
        bool $noInteraction,
        bool $forceInteractive,
        bool $interactive,
        string $reason,
        LogFormat $format,
        bool $colour,
    ): void {
        $context = ContextDetector::detect($env, $stdinTty, $stdoutTty, $noInteraction, $forceInteractive);

        self::assertSame($interactive, $context->interactive, 'interactive');
        self::assertSame($reason, $context->reason, 'reason');
        self::assertSame($format, $context->logFormat, 'log format');
        self::assertSame($colour, $context->colour, 'colour');
    }

    public function testSystemdIsFlaggedEvenWhenForcedUnattended(): void
    {
        self::assertTrue(ContextDetector::detect(['JOURNAL_STREAM' => '1:2'], false, false, true)->systemd);
        self::assertFalse(ContextDetector::detect([], false, false, true)->systemd);
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function jsonEverywhere(): iterable
    {
        yield 'interactive' => [self::TERM, true];
        yield 'unattended' => [[], false];
        yield 'systemd' => [['INVOCATION_ID' => 'x'], false];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('jsonEverywhere')]
    public function testJsonLogFormatIsAvailableInEveryMode(array $env, bool $tty): void
    {
        self::assertSame(LogFormat::Json, ContextDetector::detect($env, $tty, $tty, false, false, 'json')->logFormat);
    }

    public function testTextLogFormatKeepsTheAutomaticChoice(): void
    {
        self::assertSame(LogFormat::Plain, ContextDetector::detect([], false, false, false, false, 'text')->logFormat);
        self::assertSame(LogFormat::Human, ContextDetector::detect(self::TERM, true, true, false, false, '')->logFormat);
    }

    public function testInvalidLogFormatIsAUsageError(): void
    {
        $this->expectExceptionObject(new UsageException('Invalid --log-format "xml": use text or json'));

        ContextDetector::detect([], false, false, false, false, 'xml');
    }

    public function testInteractiveWithoutAStdinTerminalIsRefused(): void
    {
        try {
            ContextDetector::detect(self::TERM, false, true, false, true);
            self::fail('expected a usage error');
        } catch (UsageException $e) {
            self::assertSame('--interactive needs a terminal on stdin', $e->getMessage());
            self::assertSame(ExitCode::USAGE, $e->exitCode());
        }
    }

    public function testInteractiveAndNoInteractionTogetherAreRefused(): void
    {
        $this->expectExceptionObject(new UsageException('--interactive and --no-interaction cannot be used together'));

        ContextDetector::detect(self::TERM, true, true, true, true);
    }

    public function testDescribe(): void
    {
        self::assertSame(
            'unattended (systemd), log format journald, under systemd',
            ContextDetector::detect(['INVOCATION_ID' => 'x'], false, false)->describe(),
        );
        self::assertSame(
            'interactive (terminal), log format human',
            (new ExecutionContext(true, false, true, LogFormat::Human, 'terminal'))->describe(),
        );
    }
}
