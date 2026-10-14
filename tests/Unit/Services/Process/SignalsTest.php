<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Process;

use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Signals;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SignalsTest extends TestCase
{
    public function testTheFirstSignalIsReportedAndListenersSeeEveryOne(): void
    {
        $signals = new Signals();
        $seen = [];
        $id = $signals->listen(static function (int $signal) use (&$seen): void {
            $seen[] = $signal;
        });
        $signals->check();
        self::assertNull($signals->received());

        $signals->receive(InterruptedException::SIGTERM);
        $signals->receive(InterruptedException::SIGINT);
        $signals->forget($id);
        $signals->receive(InterruptedException::SIGINT);

        self::assertSame([15, 2], $seen);
        self::assertSame(15, $signals->received());
        try {
            $signals->check();
            self::fail('expected an interruption');
        } catch (InterruptedException $e) {
            self::assertSame(143, $e->exitCode());
            self::assertSame(15, $e->signal());
            self::assertSame('Interrupted by SIGTERM: cleaned up and released the lock', $e->getMessage());
        }
    }

    public function testRealSignalsReachTheHandler(): void
    {
        if (!Signals::supported()) {
            self::markTestSkipped('needs ext-pcntl');
        }
        $signals = new Signals();
        self::assertTrue($signals->install());
        self::assertTrue($signals->install());
        self::assertTrue($signals->installed());
        try {
            posix_kill(getmypid(), SIGINT);
            self::assertSame(InterruptedException::SIGINT, $signals->received());
        } finally {
            $signals->uninstall();
        }
        self::assertFalse($signals->installed());
        $signals->uninstall();
    }

    public function testCleanupRunsEveryTaskOnceMostRecentFirst(): void
    {
        $cleanup = new Cleanup();
        $order = [];
        $cleanup->add('remove the exclude file', static function () use (&$order): void {
            $order[] = 'exclude';
        });
        $dumps = $cleanup->add('empty the dump directory', static function () use (&$order): void {
            $order[] = 'dumps';
        });
        $cleanup->add('release the lock', static function () use (&$order): void {
            $order[] = 'lock';
        });
        $cleanup->add('broken', static function (): void {
            throw new RuntimeException('disk gone');
        });
        $forgotten = $cleanup->add('forgotten', static function () use (&$order): void {
            $order[] = 'forgotten';
        });
        $cleanup->forget($forgotten);

        self::assertNull($cleanup->runOne($dumps));
        self::assertNull($cleanup->runOne($dumps));
        self::assertSame(3, $cleanup->pending());
        self::assertSame(['broken: disk gone'], $cleanup->runAll());
        self::assertSame(['dumps', 'lock', 'exclude'], $order);
        self::assertSame(0, $cleanup->pending());
        self::assertSame([], $cleanup->runAll());
    }
}
