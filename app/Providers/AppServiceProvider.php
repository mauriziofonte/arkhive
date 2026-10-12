<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Providers;

use Illuminate\Support\ServiceProvider;
use Mfonte\Arkhive\Services\Context\SystemTerminal;
use Mfonte\Arkhive\Services\Context\Terminal;
use Mfonte\Arkhive\Services\Output\LogStream;
use Mfonte\Arkhive\Support\Clock;
use Mfonte\Arkhive\Support\SystemClock;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Terminal::class, SystemTerminal::class);
        $this->app->singleton(LogStream::class, static fn (): LogStream => new LogStream());
        $this->app->singleton(Clock::class, SystemClock::class);
    }
}
