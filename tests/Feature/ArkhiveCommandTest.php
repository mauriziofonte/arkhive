<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Commands\ProbeCommand;
use Tests\Support\TempDir;
use Tests\TestCase;

final class ArkhiveCommandTest extends TestCase
{
    use TempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerCommand(new ProbeCommand());
    }

    protected function tearDown(): void
    {
        ProbeCommand::$body = null;
        parent::tearDown();
    }

    public function testReturnsTheExitCodeOfTheBody(): void
    {
        ProbeCommand::$body = static fn (): int => ExitCode::WARNING;

        self::assertSame(ExitCode::WARNING, Artisan::call('test:probe'));
    }

    public function testExpectedFailuresBecomeOneErrorLineAndAHint(): void
    {
        ProbeCommand::$body = static fn (): int => throw new RefusedException('target is not empty', 'use --force');

        self::assertSame(ExitCode::CANNOT_CREATE, Artisan::call('test:probe'));
        self::assertSame(
            "2026-10-12T10:20:30+02:00 error target is not empty\n2026-10-12T10:20:30+02:00 notice use --force\n",
            $this->logOutput(),
        );
    }

    public function testExpectedFailureWithoutHint(): void
    {
        ProbeCommand::$body = static fn (): int => throw new ArkhiveException('restic failed');

        self::assertSame(ExitCode::FAILURE, Artisan::call('test:probe'));
        self::assertSame("2026-10-12T10:20:30+02:00 error restic failed\n", $this->logOutput());
    }

    public function testUsageExceptionsAreExit2(): void
    {
        ProbeCommand::$body = static fn (): int => throw UsageException::missingFlag('--target <dir>', 'The target');

        self::assertSame(ExitCode::USAGE, Artisan::call('test:probe'));
        self::assertStringContainsString('error The target is required in unattended mode: pass --target <dir>', $this->logOutput());
    }

    public function testUnexpectedErrorsAreExit1WithTheirOrigin(): void
    {
        ProbeCommand::$body = static fn (): int => throw new RuntimeException('kaboom');

        self::assertSame(ExitCode::SOFTWARE, Artisan::call('test:probe'));
        self::assertMatchesRegularExpression('/error Unexpected error: kaboom \(.+ArkhiveCommandTest\.php:\d+\)/', $this->logOutput());
    }

    public function testInvalidLogFormatIsExit2BeforeTheBodyRuns(): void
    {
        $ran = false;
        ProbeCommand::$body = static function () use (&$ran): int {
            $ran = true;

            return 0;
        };

        self::assertSame(ExitCode::USAGE, Artisan::call('test:probe', ['--log-format' => 'xml']));
        self::assertFalse($ran);
        self::assertStringContainsString('error Invalid --log-format "xml": use text or json', $this->logOutput());
    }

    public function testUnwritableLogFileIsExit2(): void
    {
        ProbeCommand::$body = static fn (): int => 0;
        $path = $this->tempDir() . '/nope/a.log';

        self::assertSame(ExitCode::CANNOT_CREATE, Artisan::call('test:probe', ['--log-file' => $path]));
        self::assertStringContainsString("Cannot write the log file {$path}", $this->logOutput());
    }

    public function testLogFileReceivesEvents(): void
    {
        $path = $this->tempDir() . '/a.log';
        ProbeCommand::$body = static function (ProbeCommand $c): int {
            $c->logger()->info('hello');

            return 0;
        };

        Artisan::call('test:probe', ['--log-file' => $path]);

        self::assertSame("2026-10-12T10:20:30+02:00 info hello\n", file_get_contents($path));
    }

    public function testInteractiveModeIsAppliedToTheInputAndColourToTheOutput(): void
    {
        $this->terminal->stdinTty = true;
        $this->terminal->stdoutTty = true;
        $this->terminal->env = ['TERM' => 'xterm'];
        $seen = null;
        ProbeCommand::$body = static function (ProbeCommand $c) use (&$seen): int {
            $seen = [$c->logger()->context()->interactive, $c->getOutput()->isDecorated()];
            $c->writeResult('<b>raw</b>');

            return 0;
        };

        Artisan::call('test:probe');

        self::assertSame([true, true], $seen);
        self::assertSame("<b>raw</b>\n", Artisan::output());
    }

    public function testInteractiveWithoutTerminalIsExit2(): void
    {
        ProbeCommand::$body = static fn (): int => 0;

        self::assertSame(ExitCode::USAGE, Artisan::call('test:probe', ['--interactive' => true]));
        self::assertStringContainsString('--interactive needs a terminal on stdin', $this->logOutput());
    }

    public function testCleanupRunsAfterSuccessAndFailuresAreWarnings(): void
    {
        $ran = [];
        ProbeCommand::$body = static function (ProbeCommand $c) use (&$ran): int {
            $c->cleanupTasks()->add('remove temp', static function () use (&$ran): void {
                $ran[] = 'temp';
            });
            $c->cleanupTasks()->add('release the lock', static function (): void {
                throw new RuntimeException('lock file vanished');
            });

            return ExitCode::SUCCESS;
        };

        self::assertSame(ExitCode::SUCCESS, Artisan::call('test:probe'));
        self::assertSame(['temp'], $ran);
        self::assertSame("2026-10-12T10:20:30+02:00 warning Cleanup failed: release the lock: lock file vanished\n", $this->logOutput());
    }

    public function testCleanupRunsAfterAFailure(): void
    {
        $ran = false;
        ProbeCommand::$body = static function (ProbeCommand $c) use (&$ran): int {
            $c->cleanupTasks()->add('remove temp', static function () use (&$ran): void {
                $ran = true;
            });

            throw new ArkhiveException('restic failed');
        };

        self::assertSame(ExitCode::FAILURE, Artisan::call('test:probe'));
        self::assertTrue($ran);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function interruptions(): iterable
    {
        yield 'SIGINT' => [InterruptedException::SIGINT, ExitCode::SIGINT, 'Interrupted by SIGINT: cleaned up and released the lock'];
        yield 'SIGTERM' => [InterruptedException::SIGTERM, ExitCode::SIGTERM, 'Interrupted by SIGTERM: cleaned up and released the lock'];
    }

    #[DataProvider('interruptions')]
    public function testASignalRunsCleanupAndExits130Or143(int $signal, int $exit, string $message): void
    {
        $ran = false;
        ProbeCommand::$body = static function (ProbeCommand $c) use (&$ran, $signal): int {
            $c->cleanupTasks()->add('empty the dump directory', static function () use (&$ran): void {
                $ran = true;
            });
            $c->interrupts()->receive($signal);
            $c->interrupts()->check();

            return ExitCode::SUCCESS;
        };

        self::assertSame($exit, Artisan::call('test:probe'));
        self::assertTrue($ran);
        self::assertSame("2026-10-12T10:20:30+02:00 error {$message}\n", $this->logOutput());
    }

    public function testSignalHandlersAreInstalledOnlyForTheCommand(): void
    {
        $installed = null;
        ProbeCommand::$body = static function (ProbeCommand $c) use (&$installed): int {
            $installed = $c->interrupts()->installed();

            return ExitCode::SUCCESS;
        };

        Artisan::call('test:probe');

        self::assertSame(\Mfonte\Arkhive\Services\Process\Signals::supported(), $installed);
        self::assertFalse($this->app->make(\Mfonte\Arkhive\Services\Process\Signals::class)->installed());
    }
}
