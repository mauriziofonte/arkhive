<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Providers;

use Illuminate\Support\ServiceProvider;
use Mfonte\Arkhive\Application\Port\EventLogs;
use Mfonte\Arkhive\Application\Port\HttpClient;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Port\Pause;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\ProcessTable;
use Mfonte\Arkhive\Application\Port\Prompter;
use Mfonte\Arkhive\Application\Port\RunStateStore;
use Mfonte\Arkhive\Application\Port\ServiceManager;
use Mfonte\Arkhive\Application\Port\ServiceUnits;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Port\Terminal;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Resilience\StaleLocks;
use Mfonte\Arkhive\Application\Service\ServiceStatus;
use Mfonte\Arkhive\Application\System\BinaryFinder;
use Mfonte\Arkhive\Application\System\CacheDirectory;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Application\System\StateDirectory;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Domain\System\Distro;
use Mfonte\Arkhive\Infrastructure\Console\StreamPrompter;
use Mfonte\Arkhive\Infrastructure\Console\SystemTerminal;
use Mfonte\Arkhive\Infrastructure\Http\CurlHttpClient;
use Mfonte\Arkhive\Infrastructure\Http\StreamHttpClient;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use Mfonte\Arkhive\Infrastructure\Logging\SocketEventLogs;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use Mfonte\Arkhive\Infrastructure\Process\SignalAwarePause;
use Mfonte\Arkhive\Infrastructure\Process\Signals;
use Mfonte\Arkhive\Infrastructure\Process\SystemProcessRunner;
use Mfonte\Arkhive\Infrastructure\Restic\ResticFactory;
use Mfonte\Arkhive\Infrastructure\State\JsonRunStateStore;
use Mfonte\Arkhive\Infrastructure\System\OsRelease;
use Mfonte\Arkhive\Infrastructure\System\ProcProcessTable;
use Mfonte\Arkhive\Infrastructure\System\SystemClock;
use Mfonte\Arkhive\Infrastructure\Systemd\NotifySocket;
use Mfonte\Arkhive\Infrastructure\Systemd\Systemctl;
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
        // systemd's notify protocol: the unit's status, more time while progress is made, watchdog pings
        $this->app->bind(EventLogs::class, static fn ($app): EventLogs => new SocketEventLogs($app->make(Clock::class), (int) getmypid()));
        $this->app->singleton(ServiceManager::class, static fn ($app): ServiceManager => NotifySocket::fromEnvironment($app->make(Terminal::class)->environment(), (int) getmypid()));
        $this->app->singleton(ServiceStatus::class);
        $this->app->singleton(ProcessRunner::class, static fn ($app): ProcessRunner => new SystemProcessRunner(
            $app->make(Signals::class),
            heartbeat: static fn () => $app->make(ServiceStatus::class)->heartbeat(),
        ));
        $this->app->singleton(BinaryFinder::class, static fn (): BinaryFinder => BinaryFinder::fromEnvironment());
        // restic's cache goes in systemd's CacheDirectory= when the unit has one, under the account's home without HOME
        $this->app->bind(ServiceUnits::class, Systemctl::class);
        $this->app->bind(SnapshotStoreFactory::class, static fn ($app): SnapshotStoreFactory => new ResticFactory(
            $app->make(ProcessRunner::class),
            $app->make(Dependencies::class),
            CacheDirectory::restic($app->make(Terminal::class)->environment(), CacheDirectory::accountHome()),
            $app->make(StaleLocks::class),
        ));
        // a restic lock left by a dead process on this host, told apart from one a live run holds
        $this->app->bind(ProcessTable::class, static fn (): ProcessTable => new ProcProcessTable());
        $this->app->bind(StaleLocks::class, static fn ($app): StaleLocks => new StaleLocks(
            $app->make(ProcessTable::class),
            $app->make(Clock::class),
            (string) (gethostname() ?: 'localhost'),
            static fn (string $repository): bool => Lock::forRepository($repository, $app->make(Terminal::class)->environment(), $app->make(Clock::class), posix_geteuid())->heldElsewhere(),
        ));
        $this->app->bind(Notifier::class, SmtpNotifier::class);
        // waits between retries end at once on SIGINT or SIGTERM
        $this->app->bind(Pause::class, static fn ($app): Pause => new SignalAwarePause(
            $app->make(Signals::class),
            static fn () => $app->make(ServiceStatus::class)->heartbeat(),
        ));
        // the outcome of each backup, maintain and drill, for status and doctor
        $this->app->bind(RunStateStore::class, static fn ($app): RunStateStore => new JsonRunStateStore(StateDirectory::resolve($app->make(Terminal::class)->environment(), posix_geteuid(), StateDirectory::systemOwner())));
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
