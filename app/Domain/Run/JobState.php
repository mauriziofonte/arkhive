<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Run;

/**
 * What is known about one job: its last run, and its last run that succeeded.
 */
final readonly class JobState
{
    public function __construct(
        public ?RunRecord $last = null,
        public ?RunRecord $lastSuccess = null,
    ) {
    }

    public function with(RunRecord $run): self
    {
        return new self($run, $run->succeeded() ? $run : $this->lastSuccess);
    }

    /**
     * @return array{last: array<string, mixed>|null, last_success: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return ['last' => $this->last?->toArray(), 'last_success' => $this->lastSuccess?->toArray()];
    }

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            return new self();
        }

        return new self(RunRecord::fromArray($data['last'] ?? null), RunRecord::fromArray($data['last_success'] ?? null));
    }
}
