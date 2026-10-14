<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

/**
 * The outcome of a restore, for the final report, --json and the next step.
 */
final readonly class RestoreReport
{
    /**
     * @param list<string> $nextSteps commands the operator can run next
     * @param list<string> $warnings
     */
    public function __construct(
        public RestorePlan $plan,
        public int $files,
        public int $bytes,
        public float $duration,
        public bool $verified,
        public array $nextSteps,
        public ?string $safetySnapshot = null,
        public array $warnings = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $plan = $this->plan;

        return [
            'snapshot' => $plan->snapshot->id,
            'snapshot_time' => $plan->snapshot->time->format(DATE_ATOM),
            'host' => $plan->snapshot->hostname,
            'selection' => $plan->selection->kind->value,
            'includes' => $plan->selection->includes,
            'excludes' => $plan->selection->excludes,
            'database' => $plan->selection->dump?->database,
            'engine' => $plan->selection->dump?->engine,
            'imported_as' => $plan->selection->importAs,
            'target' => $plan->target,
            'in_place' => $plan->inPlace,
            'owner' => $plan->owner->value,
            'safety_snapshot' => $this->safetySnapshot,
            'files' => $this->files,
            'bytes' => $this->bytes,
            'duration' => round($this->duration, 3),
            'verified' => $this->verified,
            'next_steps' => $this->nextSteps,
            'warnings' => $this->warnings,
        ];
    }
}
