<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Cli;

/**
 * spec §6 exit 2 for usage errors found before a command runs: an unknown command or option, a
 * missing or extra argument. The real entry point, since the console binds input before handle().
 */
final class UsageErrorsTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function errors(): iterable
    {
        yield 'unknown option' => [['backup', '--bogus'], 'error The "--bogus" option does not exist.', 'notice See the options with: arkhive backup --help'];
        yield 'missing argument' => [['decrypt-legacy'], 'error Not enough arguments (missing: "file").', 'notice See the options with: arkhive decrypt-legacy --help'];
        yield 'extra argument' => [['status', 'extra'], 'error No arguments expected for "status" command, got "extra".', 'notice See the options with: arkhive status --help'];
        yield 'option without its value' => [['backup', '--config'], 'error The "--config" option requires a value.', 'notice See the options with: arkhive backup --help'];
        yield 'unknown command' => [['nosuchcmd'], 'error Unknown command "nosuchcmd"', 'notice List the commands with: arkhive list'];
        yield 'mistyped command' => [['backpu'], 'error Unknown command "backpu"', 'notice Did you mean: backup? List the commands with: arkhive list'];
        yield 'unknown option without a command' => [['--bogus'], 'error The "--bogus" option does not exist.', 'notice List the commands with: arkhive list'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('errors')]
    public function testUsageErrorsExitTwo(array $args, string $error, string $hint): void
    {
        $result = Cli::run($args);

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->output());
        self::assertSame('', $result->stdout);
        self::assertMatchesRegularExpression('/^\S+ ' . preg_quote($error, '/') . '\n\S+ ' . preg_quote($hint, '/') . '\n$/', $result->stderr);
    }

    public function testTheBareCommandListsTheCommands(): void
    {
        $result = Cli::run([]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->output());
        self::assertStringContainsString('backup', $result->stdout);
        self::assertStringContainsString('self-update', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testListAndHelpStillWork(): void
    {
        $list = Cli::run(['list', '--raw']);
        self::assertSame(ExitCode::SUCCESS, $list->exitCode, $list->output());
        self::assertMatchesRegularExpression('/^schedule\s+Print the systemd units/m', $list->stdout);

        $help = Cli::run(['help', 'schedule']);
        self::assertSame(ExitCode::SUCCESS, $help->exitCode, $help->output());
        self::assertStringContainsString('--systemd', $help->stdout);
    }
}
