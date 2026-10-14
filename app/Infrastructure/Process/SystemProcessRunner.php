<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Process;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\OsException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * Runs commands with proc_open and an argv array: no shell, no pipelines, LC_ALL=C, stderr kept,
 * stdin from /dev/null unless input (a string or a file) is given, SIGINT/SIGTERM forwarded to the child.
 */
final class SystemProcessRunner implements ProcessRunner
{
    /** stderr beyond this many bytes keeps only its tail */
    public const STDERR_LIMIT = 65536;

    private const SIGKILL = 9;

    /**
     * @param array<string, string>|null $environment the inherited environment (default: getenv())
     * @param float                      $killGrace   after a timeout SIGTERM, seconds before SIGKILL
     */
    public function __construct(
        private readonly Signals $signals,
        private readonly ?array $environment = null,
        private readonly float $killGrace = 10.0,
    ) {
    }

    public function run(Command $command, ?callable $onStdoutLine = null, ?callable $onStderrLine = null): ProcessResult
    {
        $this->signals->check();
        $binary = $command->argv[0];
        if (str_contains($binary, '/') && (!is_file($binary) || !is_executable($binary))) {
            throw new UnavailableException("Cannot run {$binary}: it is not an executable file");
        }

        if ($command->inputFile !== null && (!is_file($command->inputFile) || !is_readable($command->inputFile))) {
            throw new NoInputException("Cannot run {$command->name()}: its input {$command->inputFile} is not a readable file");
        }
        $descriptors = [
            0 => match (true) {
                $command->input !== null => ['pipe', 'r'],
                $command->inputFile !== null => ['file', $command->inputFile, 'r'],
                default => ['file', '/dev/null', 'r'],
            },
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $started = hrtime(true);
        $process = @proc_open($command->argv, $descriptors, $pipes, $command->cwd, $this->environmentFor($command));
        if (!is_resource($process)) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new OsException("Cannot start {$command->name()}: {$error}");
        }

        $listener = $this->signals->listen(static function (int $signal) use ($process): void {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, $signal);
            }
        });

        try {
            [$stdout, $stderr, $exitCode, $timedOut] = $this->communicate($command, $process, $pipes, $started, $onStdoutLine, $onStderrLine);
        } finally {
            $this->signals->forget($listener);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }

        $this->signals->check();

        return new ProcessResult($command, $exitCode, $stdout, $stderr, (hrtime(true) - $started) / 1e9, $timedOut);
    }

    /**
     * @param resource                      $process
     * @param array<int, resource>          $pipes
     * @param (callable(string): void)|null $onStdoutLine
     * @param (callable(string): void)|null $onStderrLine
     *
     * @return array{string, string, int, bool}
     */
    private function communicate(Command $command, $process, array $pipes, int $started, ?callable $onStdoutLine, ?callable $onStderrLine): array
    {
        $input = $command->input;
        $inputOffset = 0;
        if ($input === null) {
            $writers = [];
        } else {
            $writers = [0 => $pipes[0]];
            stream_set_blocking($pipes[0], false);
        }
        $readers = [1 => $pipes[1], 2 => $pipes[2]];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $partial = [1 => '', 2 => ''];
        $timedOut = false;
        $outputClosed = false;
        $killAt = null;
        $exitCode = null;

        while ($readers !== [] || $writers !== [] || $exitCode === null) {
            if ($readers === [] && $writers === []) {
                $exitCode = $this->exitCodeIfDone($process);
                if ($exitCode === null) {
                    usleep(10000);
                }
            } else {
                $read = $readers;
                $write = $writers;
                $except = null;
                $ready = @stream_select($read, $write, $except, 0, 200000);
                if ($ready === false) {
                    // interrupted by a signal: the handler already ran, keep collecting
                    usleep(1000);
                }
                if ($ready !== false && $ready > 0) {
                    if ($writers !== [] && $write !== [] && $input !== null) {
                        $written = @fwrite($pipes[0], substr($input, $inputOffset, 65536));
                        $inputOffset += $written === false ? 0 : $written;
                        if ($written === false || $inputOffset >= strlen($input)) {
                            fclose($pipes[0]);
                            $writers = [];
                        }
                    }
                    foreach ($readers as $fd => $pipe) {
                        if (!in_array($pipe, $read, true)) {
                            continue;
                        }
                        $chunk = fread($pipe, 65536);
                        if ($chunk === false || ($chunk === '' && feof($pipe))) {
                            unset($readers[$fd]);
                            continue;
                        }
                        if ($fd === 1) {
                            if ($command->output !== null) {
                                if (!self::copy($command->output, $chunk)) {
                                    // the reader went away (e.g. `| head`): stop the producer instead of filling memory
                                    unset($readers[$fd]);
                                    $outputClosed = true;
                                    proc_terminate($process, InterruptedException::SIGTERM);
                                }
                            } elseif ($onStdoutLine === null) {
                                $stdout .= $chunk;
                            } else {
                                self::emitLines($partial[1], $chunk, $onStdoutLine);
                            }
                            continue;
                        }
                        $stderr .= $chunk;
                        if (strlen($stderr) > 2 * self::STDERR_LIMIT) {
                            $stderr = substr($stderr, -self::STDERR_LIMIT);
                        }
                        if ($onStderrLine !== null) {
                            self::emitLines($partial[2], $chunk, $onStderrLine);
                        }
                    }
                }
            }

            if ($command->timeout !== null && $exitCode === null) {
                $elapsed = (hrtime(true) - $started) / 1e9;
                if (!$timedOut && $elapsed >= $command->timeout) {
                    $timedOut = true;
                    $killAt = $elapsed + $this->killGrace;
                    proc_terminate($process, InterruptedException::SIGTERM);
                } elseif ($killAt !== null && $elapsed >= $killAt) {
                    proc_terminate($process, self::SIGKILL);
                    $killAt = null;
                }
            }
        }

        if ($onStdoutLine !== null && $partial[1] !== '') {
            $onStdoutLine($partial[1]);
        }
        if ($onStderrLine !== null && $partial[2] !== '') {
            $onStderrLine($partial[2]);
        }
        if (strlen($stderr) > self::STDERR_LIMIT) {
            $stderr = substr($stderr, -self::STDERR_LIMIT);
        }
        if ($outputClosed) {
            $stderr .= ($stderr === '' || str_ends_with($stderr, "\n") ? '' : "\n") . "ArkHive: the output stream was closed before {$command->name()} finished\n";
        }

        return [$stdout, $stderr, $exitCode, $timedOut];
    }

    /**
     * @param resource $stream
     */
    private static function copy($stream, string $chunk): bool
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    /**
     * @param resource $process
     */
    private function exitCodeIfDone($process): ?int
    {
        $status = proc_get_status($process);
        if ($status['running']) {
            return null;
        }
        if ($status['signaled']) {
            return 128 + $status['termsig'];
        }

        return $status['exitcode'];
    }

    /**
     * @param callable(string): void $callback
     */
    private static function emitLines(string &$partial, string $chunk, callable $callback): void
    {
        $partial .= $chunk;
        while (($newline = strpos($partial, "\n")) !== false) {
            $callback(rtrim(substr($partial, 0, $newline), "\r"));
            $partial = substr($partial, $newline + 1);
        }
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(Command $command): array
    {
        $environment = $this->environment ?? getenv();
        $environment['LC_ALL'] = 'C';
        foreach ($command->env as $name => $value) {
            if ($value === null) {
                unset($environment[$name]);
                continue;
            }
            $environment[$name] = $value;
        }

        return $environment;
    }
}
