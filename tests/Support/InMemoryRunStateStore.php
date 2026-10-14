<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\RunStateStore;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Exceptions\IoException;

/**
 * Run states kept in memory; can be made to fail like a read-only state directory.
 */
final class InMemoryRunStateStore implements RunStateStore
{
    /** @var array<string, JobState> */
    public array $states = [];

    public bool $failing = false;

    public function __construct(private readonly ?string $location = '/var/lib/arkhive')
    {
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function load(string $job): JobState
    {
        return $this->states[$job] ?? new JobState();
    }

    public function save(string $job, JobState $state): void
    {
        if ($this->failing) {
            throw new IoException("Cannot write {$this->location}/{$job}.json");
        }
        $this->states[$job] = $state;
    }
}
