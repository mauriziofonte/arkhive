<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\Cli;

/**
 * The real entry point under a pseudo-TTY and without a TTY (spec §11, §13.4).
 */
#[Group('process')]
final class ExecutionModeTest extends TestCase
{
    public function testWithoutATerminalOutputIsTimestampedPlainLinesWithoutColour(): void
    {
        $result = Cli::run(['internal:context', '--sample-log']);

        self::assertSame(0, $result->exitCode);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d warning sample warning$/m', $result->stderr);
        self::assertStringNotContainsString("\033[", $result->output());
        self::assertStringNotContainsString('⚠️', $result->output());
        self::assertSame('{"interactive":false,"systemd":false,"colour":false,"log_format":"plain","reason":"no terminal"}', trim($result->stdout));
    }

    public function testUnderAPseudoTerminalOutputIsHumanWithColour(): void
    {
        $result = Cli::run(['internal:context', '--sample-log'], pty: true);

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString("\033[33m⚠️  sample warning\033[0m", $result->stdout);
        self::assertStringContainsString('"interactive":true', $result->stdout);
        self::assertStringContainsString('"reason":"terminal"', $result->stdout);
    }

    public function testNoColourUnderAPseudoTerminal(): void
    {
        $result = Cli::run(['internal:context', '--sample-log'], ['NO_COLOR' => '1'], pty: true);

        self::assertStringContainsString('⚠️  sample warning', $result->stdout);
        self::assertStringNotContainsString("\033[33m", $result->stdout);
        self::assertStringContainsString('"colour":false', $result->stdout);
    }

    public function testNoInteractionUnderAPseudoTerminal(): void
    {
        $result = Cli::run(['internal:context', '-n'], pty: true);

        self::assertStringContainsString('"interactive":false', $result->stdout);
        self::assertStringContainsString('"reason":"--no-interaction"', $result->stdout);
    }

    public function testSystemdGetsJournaldPriorities(): void
    {
        $result = Cli::run(['internal:context', '--sample-log'], ['INVOCATION_ID' => '0123456789abcdef']);

        self::assertStringContainsString("<4>sample warning\n<3>sample error\n", $result->stderr);
        self::assertStringContainsString('"systemd":true', $result->stdout);
    }

    public function testCiIsUnattendedEvenUnderAPseudoTerminal(): void
    {
        $result = Cli::run(['internal:context'], ['CI' => 'true'], pty: true);

        self::assertStringContainsString('"reason":"CI"', $result->stdout);
    }

    public function testJsonLogFormat(): void
    {
        $result = Cli::run(['internal:context', '--sample-log', '--log-format=json']);

        $first = json_decode(strtok($result->stderr, "\n") ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertSame(['time', 'level', 'message'], array_keys($first));
        self::assertSame('sample info', $first['message']);
    }

    public function testInteractiveWithoutATerminalIsExit2(): void
    {
        $result = Cli::run(['internal:context', '--interactive']);

        self::assertSame(ExitCode::USAGE, $result->exitCode);
        self::assertStringContainsString('error --interactive needs a terminal on stdin', $result->stderr);
    }
}
