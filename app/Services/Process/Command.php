<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Process;

use InvalidArgumentException;

/**
 * An external command: an argv array (never a shell string), extra environment, working directory
 * and optional stdin. Secrets go in the environment or in files, never in argv.
 */
final readonly class Command
{
    /**
     * @param list<string>               $argv    argv[0] is the absolute path of the binary
     * @param array<string, string|null> $env     added to the inherited environment; null removes a variable
     * @param float|null                 $timeout seconds before the process gets SIGTERM (then SIGKILL)
     */
    public function __construct(
        public array $argv,
        public array $env = [],
        public ?string $cwd = null,
        public ?string $input = null,
        public ?float $timeout = null,
    ) {
        if ($argv === [] || $argv[0] === '') {
            throw new InvalidArgumentException('A command needs at least the binary');
        }
    }

    /**
     * @param array<string, string|null> $env
     */
    public function withEnv(array $env): self
    {
        return new self($this->argv, [...$this->env, ...$env], $this->cwd, $this->input, $this->timeout);
    }

    public function withInput(string $input): self
    {
        return new self($this->argv, $this->env, $this->cwd, $input, $this->timeout);
    }

    public function withTimeout(float $seconds): self
    {
        return new self($this->argv, $this->env, $this->cwd, $this->input, $seconds);
    }

    /**
     * Shell-quoted rendering for logs. Safe to log because argv never carries a secret.
     */
    public function display(): string
    {
        return implode(' ', array_map(self::quote(...), $this->argv));
    }

    public function name(): string
    {
        return basename($this->argv[0]);
    }

    private static function quote(string $arg): string
    {
        if ($arg !== '' && preg_match('#^[A-Za-z0-9_./=:,@%+-]+$#', $arg) === 1) {
            return $arg;
        }

        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }
}
