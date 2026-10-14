<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Process;

/**
 * Outcome of a finished process. stderr is always kept (the tail, when it is very long).
 */
final readonly class ProcessResult
{
    public function __construct(
        public Command $command,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $duration = 0.0,
        public bool $timedOut = false,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * "<what> failed (restic exit 1): <last stderr lines>", for error messages.
     */
    public function failure(string $what): string
    {
        $message = $this->timedOut
            ? sprintf('%s timed out after %ss (%s)', $what, (string) $this->command->timeout, $this->command->name())
            : sprintf('%s failed (%s exit %d)', $what, $this->command->name(), $this->exitCode);
        $detail = $this->stderrTail();

        return $detail === '' ? $message : $message . ': ' . $detail;
    }

    /**
     * The last non-empty stderr lines joined with " | ", at most $lines of them.
     */
    public function stderrTail(int $lines = 3): string
    {
        $all = preg_split('/\R/', trim($this->stderr)) ?: [];
        $all = array_values(array_filter(array_map('trim', $all), static fn (string $line): bool => $line !== ''));

        return implode(' | ', array_slice($all, -$lines));
    }
}
