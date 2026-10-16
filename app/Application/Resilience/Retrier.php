<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Resilience;

use Closure;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\Pause;
use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\LockedException;
use Random\Randomizer;

/**
 * Runs an operation again after a transient failure, as the policy allows. Each retry is logged
 * with its reason; the last failure is thrown unchanged, so its exit code stays precise.
 */
final class Retrier
{
    public function __construct(
        private readonly Pause $pause,
        private readonly Randomizer $random = new Randomizer(),
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(): T                           $operation
     * @param (Closure(ArkhiveException): bool)|null $retryable which failures to retry (default: the transient ones)
     *
     * @return T
     */
    public function run(Closure $operation, RetryPolicy $policy, string $what, Logger $log, ?Closure $retryable = null): mixed
    {
        $started = hrtime(true);
        // the time spent includes at least the waits, even when a test's Pause does not really wait
        $waited = 0.0;
        for ($retry = 1; ; $retry++) {
            try {
                return $operation();
            } catch (InterruptedException $e) {
                throw $e;
            } catch (ArkhiveException $e) {
                if (!($retryable === null ? $e->transient() : $retryable($e))) {
                    throw $e;
                }
                $elapsed = max((hrtime(true) - $started) / 1e9, $waited);
                $delay = $policy->delay($retry, $elapsed, $this->random->nextFloat());
                if ($delay === null) {
                    throw $e;
                }
                $progress = $policy->bounded()
                    ? sprintf('attempt %d of %d', $retry + 1, $policy->attempts)
                    : sprintf('waiting up to %ds in all, %ds so far', (int) $policy->deadline, (int) $elapsed);
                $log->warning(sprintf('%s: %s; trying again in %ds (%s)', $what, $e->getMessage(), (int) ceil($delay), $progress), ['retry' => $retry, 'delay' => round($delay, 1)]);
                $this->pause->sleep($delay);
                $waited += $delay;
            }
        }
    }

    /**
     * Retries only while the repository is locked by another run. A lock left by a dead process is
     * not waited for: it is removed and the operation run again at once when the failure carries
     * the unlock (UNLOCK_STALE), once.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function whileLocked(Closure $operation, RetryPolicy $lock, string $what, Logger $log): mixed
    {
        $unlocked = false;
        $attempt = static function () use ($operation, $what, $log, &$unlocked): mixed {
            try {
                return $operation();
            } catch (LockedException $e) {
                $unlock = $e->unlock();
                if ($unlock === null || $unlocked) {
                    throw $e;
                }
                $unlocked = true;
                $log->warning("{$what}: {$e->getMessage()}; removing it with restic unlock (UNLOCK_STALE)");
                $unlock();
                $log->info('Stale lock removed; trying again');

                return $operation();
            }
        };

        return $this->run($attempt, $lock, $what, $log, static fn (ArkhiveException $e): bool => $e instanceof LockedException && $e->transient());
    }

    /**
     * Retries every transient failure: a lock held elsewhere as $lock allows, the others (network
     * errors, timeouts) as RetryPolicy::network() does.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function transient(Closure $operation, RetryPolicy $lock, string $what, Logger $log): mixed
    {
        return $this->whileLocked(
            fn (): mixed => $this->run($operation, RetryPolicy::network(), $what, $log, static fn (ArkhiveException $e): bool => $e->transient() && !$e instanceof LockedException),
            $lock,
            $what,
            $log,
        );
    }
}
