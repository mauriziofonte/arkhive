<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Process;

use Throwable;

/**
 * Cleanup tasks (temp files, dump staging, locks) that must run however a command ends: success,
 * failure or interruption. Tasks run once, most recent first; a failing task does not stop the others.
 */
final class Cleanup
{
    /** @var array<int, array{string, callable(): void}> */
    private array $tasks = [];
    private int $next = 0;

    /**
     * @param callable(): void $task
     */
    public function add(string $description, callable $task): int
    {
        $id = $this->next++;
        $this->tasks[$id] = [$description, $task];

        return $id;
    }

    /**
     * Runs one task now (for example when the step it guards finished normally) and forgets it.
     */
    public function runOne(int $id): ?string
    {
        if (!isset($this->tasks[$id])) {
            return null;
        }
        [$description, $task] = $this->tasks[$id];
        unset($this->tasks[$id]);

        return self::attempt($description, $task);
    }

    public function forget(int $id): void
    {
        unset($this->tasks[$id]);
    }

    public function pending(): int
    {
        return count($this->tasks);
    }

    /**
     * Runs every pending task, most recent first.
     *
     * @return list<string> one message per task that failed
     */
    public function runAll(): array
    {
        $failures = [];
        foreach (array_reverse(array_keys($this->tasks)) as $id) {
            $failure = $this->runOne($id);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @param callable(): void $task
     */
    private static function attempt(string $description, callable $task): ?string
    {
        try {
            $task();

            return null;
        } catch (Throwable $e) {
            return "{$description}: {$e->getMessage()}";
        }
    }
}
