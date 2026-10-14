<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Providers;

use Illuminate\Support\ServiceProvider;
use Mfonte\Arkhive\Application\Port\HttpClient;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Port\Pause;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\Prompter;
use Mfonte\Arkhive\Application\Port\RunStateStore;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Port\Terminal;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\System\BinaryFinder;
use Mfonte\Arkhive\Application\System\StateDirectory;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Domain\System\Distro;
use Mfonte\Arkhive\Infrastructure\Console\StreamPrompter;
use Mfonte\Arkhive\Infrastructure\Console\SystemTerminal;
use Mfonte\Arkhive\Infrastructure\Http\CurlHttpClient;
use Mfonte\Arkhive\Infrastructure\Http\StreamHttpClient;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use Mfonte\Arkhive\Infrastructure\Process\SignalAwarePause;
use Mfonte\Arkhive\Infrastructure\Process\Signals;
use Mfonte\Arkhive\Infrastructure\Process\SystemProcessRunner;
use Mfonte\Arkhive\Infrastructure\Restic\ResticFactory;
use Mfonte\Arkhive\Infrastructure\State\JsonRunStateStore;
use Mfonte\Arkhive\Infrastructure\System\OsRelease;
use Mfonte\Arkhive\Infrastructure\System\SystemClock;
use Random\Randomizer;

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
        $this->app->bind(SnapshotStoreFactory::class, ResticFactory::class);
        $this->app->bind(Notifier::class, SmtpNotifier::class);
        // waits between retries end at once on SIGINT or SIGTERM
        $this->app->bind(Pause::class, SignalAwarePause::class);
        // the outcome of each backup, maintain and drill, for status and doctor
        $this->app->bind(RunStateStore::class, static fn ($app): RunStateStore => new JsonRunStateStore(StateDirectory::resolve($app->make(Terminal::class)->environment(), posix_geteuid())));
        $this->app->singleton(Distro::class, static fn (): Distro => OsRelease::detect());
        // the CSPRNG engine: drill samples and throw-away database names
        $this->app->bind(Randomizer::class, static fn (): Randomizer => new Randomizer());
        // self-update: the curl extension when loaded, PHP streams otherwise (never a curl or wget binary)
        $this->app->bind(HttpClient::class, static function ($app): HttpClient {
            $environment = $app->make(Terminal::class)->environment();

            return extension_loaded('curl') ? new CurlHttpClient($environment) : new StreamHttpClient($environment);
        });
        // questions on stderr next to the log, answers from stdin: stdout stays for results
        $this->app->bind(Prompter::class, static fn ($app): Prompter => new StreamPrompter(STDIN, $app->make(LogStream::class)->resource(), $app->make(Signals::class), getenv('NO_COLOR') === false));
    }
}
