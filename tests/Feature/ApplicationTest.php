<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\Pause;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\ServiceManager;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Infrastructure\Systemd\NotifySocket;
use Tests\Support\RecordingServiceManager;
use Tests\TestCase;

final class ApplicationTest extends TestCase
{
    public function testTheApplicationRegistersItsCommands(): void
    {
        $commands = array_keys(Artisan::all());

        foreach (['backup', 'restore', 'ls', 'find', 'mount', 'maintain', 'status', 'init', 'drill', 'doctor', 'decrypt-legacy', 'self-update', 'schedule', 'notify'] as $command) {
            self::assertContains($command, $commands);
        }
    }

    public function testRunningChildrenAndWaitingKeepTheWatchdogFed(): void
    {
        $systemd = new RecordingServiceManager(watchdog: 0.05);
        $this->app->instance(ServiceManager::class, $systemd);
        $this->app->forgetInstance(Pause::class);

        $this->app->make(ProcessRunner::class)->run(new Command([PHP_BINARY, '-r', 'usleep(300000);']));
        $afterChild = count($systemd->messages);
        $this->app->make(Pause::class)->sleep(0.3);

        self::assertGreaterThanOrEqual(2, $afterChild);
        self::assertGreaterThan($afterChild, count($systemd->messages));
        self::assertSame(['WATCHDOG' => '1'], $systemd->messages[0]);
    }

    public function testTheServiceManagerComesFromTheEnvironment(): void
    {
        $this->terminal->env = ['NOTIFY_SOCKET' => '/run/systemd/notify', 'WATCHDOG_USEC' => '60000000'];
        $this->app->forgetInstance(ServiceManager::class);

        $manager = $this->app->make(ServiceManager::class);
        self::assertInstanceOf(NotifySocket::class, $manager);
        self::assertTrue($manager->supervised());
        self::assertSame(30.0, $manager->watchdogInterval());
    }
}
