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
     * What of this state may belong to a repository: its own records, and those of 2.1.0-rc.1 that
     * name no repository (taken over by the first repository that reads them).
     */
    public function forRepository(string $key): self
    {
        $belongs = static fn (?RunRecord $run): ?RunRecord => $run !== null && ($run->repository === null || $run->repository === $key) ? $run : null;

        return new self($belongs($this->last), $belongs($this->lastSuccess));
    }

    public function isEmpty(): bool
    {
        return $this->last === null && $this->lastSuccess === null;
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
