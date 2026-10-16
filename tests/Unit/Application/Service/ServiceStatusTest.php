<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service;

use Mfonte\Arkhive\Application\Service\ServiceStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingServiceManager;

final class ServiceStatusTest extends TestCase
{
    private float $time = 100.0;

    private function service(RecordingServiceManager $manager): ServiceStatus
    {
        return new ServiceStatus($manager, fn (): float => $this->time);
    }

    public function testStepsBecomeTheStatusAndTheEndIsReported(): void
    {
        $manager = new RecordingServiceManager();
        $status = $this->service($manager);

        $status->started('arkhive backup started');
        $status->step("Dumping MySQL database shop\n  (InnoDB)");
        $status->finished(69, 'arkhive backup failed with exit 69 (UNAVAILABLE): no route');

        self::assertSame([
            ['READY' => '1', 'STATUS' => 'arkhive backup started'],
            ['STATUS' => 'Dumping MySQL database shop (InnoDB)'],
            ['STOPPING' => '1', 'STATUS' => 'arkhive backup failed with exit 69 (UNAVAILABLE): no route', 'EXIT_STATUS' => '69'],
        ], $manager->messages);
        self::assertTrue($status->supervised());
    }

    public function testProgressIsRateLimitedAndAsksForMoreTime(): void
    {
        $manager = new RecordingServiceManager();
        $status = $this->service($manager);

        $status->progress('Backing up: 10%');
        $this->time += 1.0;
        $status->progress('Backing up: 11%');
        $this->time += 1.0;
        $status->progress('Backing up: 12%');

        $extend = (string) (ServiceStatus::EXTEND * 1_000_000);
        self::assertSame([
            ['STATUS' => 'Backing up: 10%', 'EXTEND_TIMEOUT_USEC' => $extend],
            ['STATUS' => 'Backing up: 12%', 'EXTEND_TIMEOUT_USEC' => $extend],
        ], $manager->messages);
        self::assertSame('1800000000', $extend, 'half an hour without progress past TimeoutStartSec stops the run');
    }

    public function testWatchdogPingsGoWithEveryMessageAndWhenDue(): void
    {
        $manager = new RecordingServiceManager(watchdog: 30.0);
        $status = $this->service($manager);

        $status->step('Backing up');
        $this->time += 29.0;
        $status->heartbeat();
        $this->time += 1.0;
        $status->heartbeat();
        $status->heartbeat();
        $this->time += 1.0;
        $status->progress('Backing up: 1%');
        $this->time += 1.0;
        $status->progress('Backing up: 2%');

        self::assertSame([
            ['STATUS' => 'Backing up', 'WATCHDOG' => '1'],
            ['WATCHDOG' => '1'],
            ['STATUS' => 'Backing up: 1%', 'EXTEND_TIMEOUT_USEC' => '1800000000', 'WATCHDOG' => '1'],
        ], $manager->messages, 'one ping per interval; a rate-limited progress only pings when due');
    }

    public function testNothingIsSentWithoutAServiceManager(): void
    {
        $manager = new RecordingServiceManager(listening: false, watchdog: 1.0);
        $status = $this->service($manager);

        $status->started('x');
        $status->step('x');
        $status->progress('x');
        $status->finished(0, 'x');

        self::assertSame([], $manager->messages);
        self::assertFalse($status->supervised());
    }

    public function testWithoutAWatchdogHeartbeatsSendNothing(): void
    {
        $manager = new RecordingServiceManager();
        $this->service($manager)->heartbeat();

        self::assertSame([], $manager->messages);
    }

    public function testALongStatusIsShortened(): void
    {
        $manager = new RecordingServiceManager();
        $this->service($manager)->step(str_repeat('é', 250));

        self::assertSame(str_repeat('é', 199) . '…', $manager->messages[0]['STATUS']);
    }
}
