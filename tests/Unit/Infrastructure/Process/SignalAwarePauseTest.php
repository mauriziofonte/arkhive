<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Process;

use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Infrastructure\Process\SignalAwarePause;
use Mfonte\Arkhive\Infrastructure\Process\Signals;
use PHPUnit\Framework\TestCase;

final class SignalAwarePauseTest extends TestCase
{
    public function testItWaitsAboutTheGivenTime(): void
    {
        $started = hrtime(true);
        (new SignalAwarePause(new Signals()))->sleep(0.25);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertGreaterThanOrEqual(0.25, $elapsed);
        self::assertLessThan(1.0, $elapsed);
    }

    public function testTheHeartbeatRunsDuringTheWait(): void
    {
        $beats = 0;
        (new SignalAwarePause(new Signals(), static function () use (&$beats): void {
            $beats++;
        }))->sleep(0.35);

        self::assertGreaterThanOrEqual(3, $beats);
    }

    public function testAZeroOrNegativeWaitReturnsAtOnce(): void
    {
        $started = hrtime(true);
        (new SignalAwarePause(new Signals()))->sleep(0.0);
        (new SignalAwarePause(new Signals()))->sleep(-5.0);

        self::assertLessThan(0.05, (hrtime(true) - $started) / 1e9);
    }

    public function testASignalReceivedBeforeTheWaitInterruptsIt(): void
    {
        $signals = new Signals();
        $signals->receive(InterruptedException::SIGTERM);
        $started = hrtime(true);

        try {
            (new SignalAwarePause($signals))->sleep(30.0);
            self::fail('expected an interruption');
        } catch (InterruptedException $e) {
            self::assertSame(InterruptedException::SIGTERM, $e->signal());
        }
        self::assertLessThan(0.05, (hrtime(true) - $started) / 1e9);
    }

    public function testARealSignalDuringTheWaitInterruptsItWithinASlice(): void
    {
        if (!Signals::supported() || !function_exists('pcntl_alarm')) {
            self::markTestSkipped('needs ext-pcntl');
        }
        $signals = new Signals();
        self::assertTrue($signals->install());
        pcntl_signal(SIGALRM, static function (): void {
            posix_kill(getmypid(), SIGINT);
        });
        $started = hrtime(true);
        pcntl_alarm(1);
        try {
            (new SignalAwarePause($signals))->sleep(30.0);
            self::fail('expected an interruption');
        } catch (InterruptedException $e) {
            self::assertSame(InterruptedException::SIGINT, $e->signal());
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            $signals->uninstall();
        }
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
    }
}
