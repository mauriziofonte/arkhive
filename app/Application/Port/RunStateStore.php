<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * Where the outcome of each job is kept between runs.
 */
interface RunStateStore
{
    /**
     * The directory or location, for messages; null when there is none (no state directory).
     */
    public function location(): ?string;

    /**
     * The state of a job; empty when nothing was recorded yet or the record is unreadable.
     */
    public function load(string $job): JobState;

    /**
     * Replaces the state of a job, atomically: a reader sees the old state or the new one.
     *
     * @throws ArkhiveException when it cannot be written
     */
    public function save(string $job, JobState $state): void;
}
