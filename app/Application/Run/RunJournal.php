<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Run;

use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\RunStateStore;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * Records how each scheduled job ended, for status, doctor and monitoring. Recording never changes
 * the outcome of the run: a state directory that cannot be written is a warning.
 */
final class RunJournal
{
    public function __construct(private readonly RunStateStore $store)
    {
    }

    public function record(RunRecord $run, Logger $log): void
    {
        if ($this->store->location() === null) {
            $log->debug('No state directory: the outcome of this run is not recorded');

            return;
        }
        try {
            $this->store->save($run->job, $this->store->load($run->job)->with($run));
        } catch (ArkhiveException $e) {
            $log->warning("Could not record the outcome of this {$run->job} in {$this->store->location()}: {$e->getMessage()}");
        }
    }

    /**
     * @return array<string, JobState> every job, in RunRecord::JOBS order
     */
    public function states(): array
    {
        $states = [];
        foreach (RunRecord::JOBS as $job) {
            $states[$job] = $this->store->load($job);
        }

        return $states;
    }

    public function location(): ?string
    {
        return $this->store->location();
    }
}
