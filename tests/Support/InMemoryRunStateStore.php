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
    /** @var array<string, JobState> the last run on this host, by job */
    public array $states = [];

    /** @var array<string, array<string, JobState>> by RepositoryKey, then job */
    public array $repositories = [];

    public bool $failing = false;

    public function __construct(private readonly ?string $location = '/var/lib/arkhive')
    {
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function load(string $job, ?string $repository = null): JobState
    {
        if ($repository === null) {
            return $this->states[$job] ?? new JobState();
        }

        return $this->repositories[$repository][$job] ?? ($this->states[$job] ?? new JobState())->forRepository($repository);
    }

    public function save(string $job, JobState $state, ?string $repository = null): void
    {
        if ($this->failing) {
            throw new IoException("Cannot write {$this->location}/" . ($repository === null ? '' : "{$repository}/") . "{$job}.json");
        }
        if ($repository === null) {
            $this->states[$job] = $state;
        } else {
            $this->repositories[$repository][$job] = $state;
        }
    }
}
