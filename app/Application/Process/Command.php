<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Process;

use InvalidArgumentException;

/**
 * An external command: an argv array (never a shell string), extra environment, working directory,
 * optional stdin (a string or a file) and an optional stream that receives stdout as it arrives.
 * Secrets go in the environment or in files, never in argv.
 */
final readonly class Command
{
    /**
     * @param list<string>               $argv    argv[0] is the absolute path of the binary
     * @param array<string, string|null> $env     added to the inherited environment; null removes a variable
     * @param float|null                 $timeout   seconds before the process gets SIGTERM (then SIGKILL)
     * @param string|null                $inputFile stdin read from this file (large inputs, e.g. a dump to import)
     * @param resource|null              $output    stdout copied to this stream as it arrives, not kept in memory
     */
    public function __construct(
        public array $argv,
        public array $env = [],
        public ?string $cwd = null,
        public ?string $input = null,
        public ?float $timeout = null,
        public ?string $inputFile = null,
        public mixed $output = null,
    ) {
        if ($argv === [] || $argv[0] === '') {
            throw new InvalidArgumentException('A command needs at least the binary');
        }
        if ($input !== null && $inputFile !== null) {
            throw new InvalidArgumentException('A command reads stdin from a string or from a file, not both');
        }
        if ($output !== null && !is_resource($output)) {
            throw new InvalidArgumentException('The output of a command must be a stream');
        }
    }

    /**
     * @param array<string, string|null> $env
     */
    public function withEnv(array $env): self
    {
        return new self($this->argv, [...$this->env, ...$env], $this->cwd, $this->input, $this->timeout, $this->inputFile, $this->output);
    }

    public function withInput(string $input): self
    {
        return new self($this->argv, $this->env, $this->cwd, $input, $this->timeout, null, $this->output);
    }

    public function withInputFile(string $path): self
    {
        return new self($this->argv, $this->env, $this->cwd, null, $this->timeout, $path, $this->output);
    }

    /**
     * @param resource $stream
     */
    public function withOutput($stream): self
    {
        return new self($this->argv, $this->env, $this->cwd, $this->input, $this->timeout, $this->inputFile, $stream);
    }

    public function withTimeout(float $seconds): self
    {
        return new self($this->argv, $this->env, $this->cwd, $this->input, $seconds, $this->inputFile, $this->output);
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
