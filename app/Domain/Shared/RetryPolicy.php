<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Shared;

/**
 * How often and how long to wait before trying a transient failure again: exponential backoff
 * with jitter, capped per wait and in total.
 */
final readonly class RetryPolicy
{
    /** no limit on the number of tries: only the deadline ends them */
    public const UNBOUNDED = PHP_INT_MAX;

    /**
     * @param int   $attempts     tries in all, the first included (1 = no retry)
     * @param float $initialDelay seconds before the first retry
     * @param float $multiplier   growth of each following wait
     * @param float $maxDelay     longest single wait
     * @param float $jitter       each wait varies by up to this fraction, so that hosts do not retry in step
     * @param float $deadline     no retry starts later than this many seconds after the first try
     */
    public function __construct(
        public int $attempts,
        public float $initialDelay,
        public float $multiplier = 2.0,
        public float $maxDelay = 60.0,
        public float $jitter = 0.2,
        public float $deadline = 600.0,
    ) {
    }

    public static function none(): self
    {
        return new self(1, 0.0);
    }

    /**
     * A repository or database server that does not answer: 3 tries over about 20 seconds.
     */
    public static function network(): self
    {
        return new self(3, 5.0, 3.0, 30.0);
    }

    /**
     * A repository locked by another run: tries again (5s, 10s, 20s, then every minute) until $wait
     * seconds have passed, the last try at the deadline itself; 0 does not wait.
     */
    public static function lock(int $wait): self
    {
        return $wait <= 0 ? self::none() : new self(self::UNBOUNDED, 5.0, 2.0, 60.0, deadline: (float) $wait);
    }

    public function bounded(): bool
    {
        return $this->attempts !== self::UNBOUNDED;
    }

    /**
     * An SMTP server that answers 4xx (greylisting, rate limit): 3 tries over about a minute.
     */
    public static function mail(): self
    {
        return new self(3, 15.0, 3.0, 60.0);
    }

    /**
     * Seconds to wait before retry number $retry (1 for the first retry), or null when no retry is
     * left. $random is uniform in [0, 1).
     */
    public function delay(int $retry, float $elapsed, float $random): ?float
    {
        if ($retry < 1 || $retry >= $this->attempts || $elapsed >= $this->deadline) {
            return null;
        }
        $base = min($this->maxDelay, $this->initialDelay * $this->multiplier ** ($retry - 1));
        $delay = $base * (1 + $this->jitter * (2 * $random - 1));

        return max(0.0, min($delay, $this->deadline - $elapsed));
    }
}
