<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Resilience;

use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\LockedException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPause;

final class RetrierTest extends TestCase
{
    private RecordingPause $pause;
    private LogStream $stream;
    private Logger $log;
    private Retrier $retrier;

    protected function setUp(): void
    {
        $this->pause = new RecordingPause();
        $this->stream = LogStream::memory();
        $this->log = new Logger(new ExecutionContext(false, false, false, LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock());
        $this->retrier = new Retrier($this->pause, new Randomizer(new Mt19937(1)));
    }

    /**
     * An operation that throws the given failures in turn, then returns 'done'.
     *
     * @param list<ArkhiveException> $failures
     *
     * @return \Closure(): string
     */
    private static function failing(array $failures, int &$calls): \Closure
    {
        return static function () use (&$failures, &$calls): string {
            $calls++;
            $next = array_shift($failures);
            if ($next !== null) {
                throw $next;
            }

            return 'done';
        };
    }

    public function testATransientFailureIsTriedAgainAndLogged(): void
    {
        $calls = 0;
        $operation = self::failing([UnavailableException::network('Connection refused'), new TemporaryException('timed out')], $calls);

        self::assertSame('done', $this->retrier->run($operation, RetryPolicy::network(), 'Opening the repository', $this->log));

        self::assertSame(3, $calls);
        self::assertCount(2, $this->pause->waits);
        self::assertEqualsWithDelta(5.0, $this->pause->waits[0], 1.0);
        self::assertEqualsWithDelta(15.0, $this->pause->waits[1], 3.0);
        $log = $this->stream->contents();
        self::assertMatchesRegularExpression('/warning Opening the repository: Connection refused; trying again in [4-6]s \(attempt 2 of 3\)/', $log);
        self::assertMatchesRegularExpression('/warning Opening the repository: timed out; trying again in (1[2-8])s \(attempt 3 of 3\)/', $log);
    }

    public function testTheLastFailureIsThrownUnchanged(): void
    {
        $calls = 0;
        $last = UnavailableException::network('still refused');
        $operation = self::failing([UnavailableException::network('refused'), UnavailableException::network('refused'), $last], $calls);

        try {
            $this->retrier->run($operation, RetryPolicy::network(), 'Opening the repository', $this->log);
            self::fail('expected the last failure');
        } catch (UnavailableException $e) {
            self::assertSame($last, $e);
        }
        self::assertSame(3, $calls);
        self::assertCount(2, $this->pause->waits);
    }

    public function testPermanentFailuresAreNeverRetried(): void
    {
        $calls = 0;
        $wrong = new PermissionException('Wrong password');

        try {
            $this->retrier->run(self::failing([$wrong], $calls), RetryPolicy::network(), 'Opening the repository', $this->log);
            self::fail('expected the failure');
        } catch (PermissionException $e) {
            self::assertSame($wrong, $e);
        }
        self::assertSame(1, $calls);
        self::assertSame([], $this->pause->waits);
        self::assertSame('', $this->stream->contents());
    }

    public function testAnInterruptionDuringTheWaitStopsAtOnce(): void
    {
        $calls = 0;
        $pause = new RecordingPause(static function (): void {
            throw new InterruptedException(InterruptedException::SIGTERM);
        });

        $this->expectException(InterruptedException::class);
        try {
            (new Retrier($pause))->run(self::failing([new LockedException('locked')], $calls), RetryPolicy::lock(60), 'Waiting', $this->log);
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testAnInterruptionFromTheOperationIsNotRetried(): void
    {
        $calls = 0;
        $retryEverything = static fn (): bool => true;

        $this->expectException(InterruptedException::class);
        try {
            $this->retrier->run(self::failing([new InterruptedException(InterruptedException::SIGINT)], $calls), RetryPolicy::network(), 'x', $this->log, $retryEverything);
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testWhileLockedRetriesOnlyLocksAndReportsTheWaitSoFar(): void
    {
        $calls = 0;
        self::assertSame('done', $this->retrier->whileLocked(self::failing([new LockedException('The restic repository is locked by root@web1')], $calls), RetryPolicy::lock(300), 'restic backup', $this->log));
        self::assertSame(2, $calls);
        self::assertMatchesRegularExpression('/warning restic backup: The restic repository is locked by root@web1; trying again in [4-6]s \(waiting up to 300s in all, 0s so far\)/', $this->stream->contents());

        $calls = 0;
        $this->expectException(UnavailableException::class);
        try {
            $this->retrier->whileLocked(self::failing([UnavailableException::network('refused')], $calls), RetryPolicy::lock(300), 'restic backup', $this->log);
        } finally {
            self::assertSame(1, $calls, 'a network failure mid-backup is not retried');
        }
    }

    public function testALockStillHeldAfterLockWaitIsTheLastFailure(): void
    {
        $calls = 0;
        $failures = array_fill(0, 10, new LockedException('locked'));
        $this->expectException(LockedException::class);
        try {
            $this->retrier->whileLocked(self::failing($failures, $calls), new RetryPolicy(RetryPolicy::UNBOUNDED, 5.0, 2.0, 60.0, 0.0, 30.0), 'Waiting', $this->log);
        } finally {
            self::assertSame([5.0, 10.0, 15.0], $this->pause->waits, 'the last wait ends at LOCK_WAIT, then one more try');
            self::assertSame(4, $calls);
            self::assertStringContainsString('(waiting up to 30s in all, 15s so far)', $this->stream->contents());
        }
    }

    public function testLockWaitZeroFailsAtOnce(): void
    {
        $calls = 0;
        $this->expectException(LockedException::class);
        try {
            $this->retrier->whileLocked(self::failing([new LockedException('locked')], $calls), RetryPolicy::lock(0), 'Waiting', $this->log);
        } finally {
            self::assertSame(1, $calls);
            self::assertSame([], $this->pause->waits);
        }
    }

    public function testTransientRetriesLocksAndNetworkErrorsEachByItsOwnPolicy(): void
    {
        $calls = 0;
        $operation = self::failing([
            UnavailableException::network('refused'),
            new LockedException('locked'),
            new LockedException('locked'),
            UnavailableException::network('refused'),
            UnavailableException::network('refused'),
        ], $calls);

        self::assertSame('done', $this->retrier->transient($operation, RetryPolicy::lock(300), 'Opening the repository', $this->log));
        self::assertSame(6, $calls);
        self::assertCount(5, $this->pause->waits);

        $calls = 0;
        $last = UnavailableException::network('refused');
        $this->expectExceptionObject($last);
        try {
            $this->retrier->transient(self::failing([UnavailableException::network('refused'), UnavailableException::network('refused'), $last], $calls), RetryPolicy::lock(300), 'Opening the repository', $this->log);
        } finally {
            self::assertSame(3, $calls, 'network errors: three tries, however long LOCK_WAIT is');
        }
    }

    public function testTheDefaultRandomizerIsUsedWhenNoneIsGiven(): void
    {
        $calls = 0;
        self::assertSame('done', (new Retrier($this->pause))->run(self::failing([new TemporaryException('busy')], $calls), RetryPolicy::network(), 'x', $this->log));
        self::assertGreaterThanOrEqual(4.0, $this->pause->waits[0]);
        self::assertLessThanOrEqual(6.0, $this->pause->waits[0]);
    }
}
