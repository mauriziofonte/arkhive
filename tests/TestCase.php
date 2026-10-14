<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Mfonte\Arkhive\Application\Port\Pause;
use Mfonte\Arkhive\Application\Port\Terminal;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FakeTerminal;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPause;

/**
 * In-process command tests: the terminal, the log stream, the clock and the waits between retries
 * are fakes.
 */
abstract class TestCase extends BaseTestCase
{
    protected FakeTerminal $terminal;
    protected LogStream $logStream;
    protected FixedClock $clock;
    protected RecordingPause $pause;

    protected function setUp(): void
    {
        parent::setUp();

        $this->terminal = new FakeTerminal();
        $this->logStream = LogStream::memory();
        $this->clock = new FixedClock();
        $this->app->instance(Terminal::class, $this->terminal);
        $this->app->instance(LogStream::class, $this->logStream);
        $this->app->instance(Clock::class, $this->clock);
        $this->pause = new RecordingPause();
        $this->app->instance(Pause::class, $this->pause);
    }

    protected function registerCommand(Command $command): void
    {
        $kernel = $this->app->make(Kernel::class);
        assert($kernel instanceof \LaravelZero\Framework\Kernel);
        $kernel->registerCommand($command);
    }

    protected function logOutput(): string
    {
        return $this->logStream->contents();
    }
}
