<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Runs the source `arkhive` entry point as a real process: without a TTY, or under a
 * pseudo-TTY through `script -qec` (util-linux), to test the execution-context detection end to end.
 */
final class Cli
{
    /**
     * @param list<string>          $args
     * @param array<string, string> $env        added to a minimal environment (PATH, HOME, TERM)
     * @param list<string>          $phpOptions options for the PHP binary itself (e.g. -n)
     */
    public static function run(array $args, array $env = [], bool $pty = false, string $stdin = '', ?string $entry = null, array $phpOptions = [], ?string $cwd = null): CliResult
    {
        $entry ??= dirname(__DIR__, 2) . '/arkhive';
        $argv = array_merge([PHP_BINARY], $phpOptions, [$entry], $args);
        if ($pty) {
            $argv = ['script', '-qec', implode(' ', array_map('escapeshellarg', $argv)), '/dev/null'];
        }

        $base = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'TERM' => 'xterm-256color',
            'LC_ALL' => 'C',
        ];
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env + $base);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $argv));
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new CliResult(proc_close($process), str_replace("\r\n", "\n", $stdout), $stderr);
    }
}
