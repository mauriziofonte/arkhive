<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testBackoffGrowsUpToTheCapWithoutJitterAtTheMiddle(): void
    {
        $policy = new RetryPolicy(6, 5.0, 3.0, 30.0);

        self::assertSame([5.0, 15.0, 30.0, 30.0, 30.0], array_map(static fn (int $retry): ?float => $policy->delay($retry, 0.0, 0.5), range(1, 5)));
        self::assertNull($policy->delay(6, 0.0, 0.5), 'six tries in all');
        self::assertNull($policy->delay(0, 0.0, 0.5));
    }

    public function testJitterSpreadsEachWaitByTheConfiguredFraction(): void
    {
        $policy = new RetryPolicy(3, 10.0, jitter: 0.2);

        self::assertEqualsWithDelta(8.0, $policy->delay(1, 0.0, 0.0), 1e-9);
        self::assertEqualsWithDelta(12.0, $policy->delay(1, 0.0, 1.0), 1e-9);
        self::assertEqualsWithDelta(16.0, $policy->delay(2, 0.0, 0.0), 1e-9);
        self::assertEqualsWithDelta(0.0, (new RetryPolicy(3, 10.0, jitter: 2.0))->delay(1, 0.0, 0.0), 1e-9, 'never negative');
    }

    public function testTheDeadlineEndsRetriesAndShortensTheLastWait(): void
    {
        $policy = new RetryPolicy(10, 10.0, deadline: 25.0);

        self::assertSame(10.0, $policy->delay(1, 0.0, 0.5));
        self::assertSame(5.0, $policy->delay(2, 20.0, 0.5), 'the last try happens at the deadline');
        self::assertNull($policy->delay(3, 25.0, 0.5));
        self::assertNull($policy->delay(3, 30.0, 0.5));
    }

    public function testThePresets(): void
    {
        self::assertNull(RetryPolicy::none()->delay(1, 0.0, 0.5));
        self::assertTrue(RetryPolicy::none()->bounded());

        $network = RetryPolicy::network();
        self::assertSame(3, $network->attempts);
        self::assertSame([5.0, 15.0], [$network->delay(1, 0.0, 0.5), $network->delay(2, 5.0, 0.5)]);
        self::assertNull($network->delay(3, 20.0, 0.5));

        $mail = RetryPolicy::mail();
        self::assertSame([15.0, 45.0], [$mail->delay(1, 0.0, 0.5), $mail->delay(2, 15.0, 0.5)]);
        self::assertNull($mail->delay(3, 60.0, 0.5));
    }

    public function testTheLockPolicyWaitsUntilLockWaitWhateverTheNumberOfTries(): void
    {
        $lock = RetryPolicy::lock(300);

        self::assertFalse($lock->bounded());
        self::assertSame(300.0, $lock->deadline);
        self::assertSame([5.0, 10.0, 20.0, 40.0, 60.0, 60.0], array_map(static fn (int $retry): ?float => $lock->delay($retry, 0.0, 0.5), range(1, 6)));
        self::assertSame(60.0, $lock->delay(1000, 100.0, 0.5), 'no limit on the number of tries');
        self::assertSame(15.0, $lock->delay(9, 285.0, 0.5));
        self::assertNull($lock->delay(10, 300.0, 0.5));

        self::assertNull(RetryPolicy::lock(0)->delay(1, 0.0, 0.5), 'LOCK_WAIT=0 fails at once');
        self::assertNull(RetryPolicy::lock(-1)->delay(1, 0.0, 0.5));
        self::assertSame(1.0, RetryPolicy::lock(1)->delay(1, 0.0, 0.5));
    }
}
