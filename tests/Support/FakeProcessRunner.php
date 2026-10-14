<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use RuntimeException;

/**
 * Records every command and answers it with the handler registered for its binary name.
 * A handler returns an exit code, [exit, stdout, stderr], or a ProcessResult; it can inspect files
 * (credentials, exclude lists) while they exist, i.e. while the "process" runs.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<Command> */
    public array $commands = [];

    /** @var array<string, callable(Command, ?callable, ?callable): (int|array{int, string, string}|ProcessResult)> */
    private array $handlers = [];

    /**
     * @param callable(Command, ?callable, ?callable): (int|array{int, string, string}|ProcessResult) $handler
     */
    public function on(string $binary, callable $handler): self
    {
        $this->handlers[$binary] = $handler;

        return $this;
    }

    public function run(Command $command, ?callable $onStdoutLine = null, ?callable $onStderrLine = null): ProcessResult
    {
        $this->commands[] = $command;
        $handler = $this->handlers[$command->name()] ?? null;
        if ($handler === null) {
            throw new RuntimeException('unexpected command: ' . $command->display());
        }
        $answer = $handler($command, $onStdoutLine, $onStderrLine);
        if ($answer instanceof ProcessResult) {
            return $answer;
        }
        [$exit, $stdout, $stderr] = is_int($answer) ? [$answer, '', ''] : $answer;
        if ($command->output !== null) {
            fwrite($command->output, $stdout);
            $stdout = '';
        } elseif ($onStdoutLine !== null) {
            foreach (explode("\n", rtrim($stdout, "\n")) as $line) {
                if ($stdout !== '') {
                    $onStdoutLine($line);
                }
            }
            $stdout = '';
        }

        return new ProcessResult($command, $exit, $stdout, $stderr, 0.25);
    }

    /**
     * @return list<string> the commands run, as displayed in logs
     */
    public function displayed(): array
    {
        return array_map(static fn (Command $command): string => $command->display(), $this->commands);
    }

    /**
     * Every argv of every command, for "no secret on the command line" checks.
     */
    public function everythingOnTheCommandLine(): string
    {
        return implode("\n", array_map(static fn (Command $command): string => implode(' ', $command->argv), $this->commands));
    }
}
