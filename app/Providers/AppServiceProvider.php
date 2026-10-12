<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Providers;

use Illuminate\Support\ServiceProvider;
use Mfonte\Arkhive\Services\Context\SystemTerminal;
use Mfonte\Arkhive\Services\Context\Terminal;
use Mfonte\Arkhive\Services\Output\LogStream;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\Process\Signals;
use Mfonte\Arkhive\Services\Process\SystemProcessRunner;
use Mfonte\Arkhive\Services\Restore\Prompter;
use Mfonte\Arkhive\Services\Restore\StreamPrompter;
use Mfonte\Arkhive\Services\System\BinaryFinder;
use Mfonte\Arkhive\Services\System\Distro;
use Mfonte\Arkhive\Support\Clock;
use Mfonte\Arkhive\Support\SystemClock;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Terminal::class, SystemTerminal::class);
        $this->app->singleton(LogStream::class, static fn (): LogStream => new LogStream());
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(Signals::class);
        $this->app->singleton(Cleanup::class);
        $this->app->singleton(ProcessRunner::class, SystemProcessRunner::class);
        $this->app->singleton(BinaryFinder::class, static fn (): BinaryFinder => BinaryFinder::fromEnvironment());
        $this->app->singleton(Distro::class, static fn (): Distro => Distro::detect());
        // questions on stderr next to the log, answers from stdin: stdout stays for results
        $this->app->bind(Prompter::class, static fn ($app): Prompter => new StreamPrompter(STDIN, $app->make(LogStream::class)->resource(), $app->make(Signals::class), getenv('NO_COLOR') === false));
    }
}
