<?php

if (!function_exists('proc_exec')) {
    /**
     * Executes a shell command, captures output, errors, and returns the exit code.
     * Attention: $command *IS NOT* sanitized. The caller is responsible for sanitizing the command.
     * If a $progressCallback is provided, progress from `pv` (written to stderr) is parsed.
     *
     * @example proc_exec('tar cf - /dir/ | pv -f -s $(du -sb %s | awk \'{print $1}\') | gzip > output.tgz', function($percent, $eta, $elapsed, $speed, $transferred) {
     *    echo "Progress: {$percent}% ETA: {$eta} Elapsed: {$elapsed} Speed: {$speed} Transferred: {$transferred}\n";
     * });
     * @param string $command
     * @param callable|null $progressCallback A function($percent, $etaSec, $elapsedTimeSec, $speed, $transferredSize).
     *
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    function proc_exec(string $command, ?callable $progressCallback = null): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is unavailable on this system.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $pipes = [];
        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not open process for command: ' . $command);
        }

        // We'll never write to stdin
        fclose($pipes[0]);

        // Non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdOutBuffer = '';
        $stdErrBuffer = '';
        $stdOut = '';
        $stdErr = '';

        // The default `pv` line might look like this: " 340MiB 0:00:07 [60.8MiB/s] [==>    ]  5% ETA 0:02:11\r"
        $progressRegex = '/^([\d\.]+[KMGTP]?i?B?)\s+(\d{1,2}:\d{2}(:\d{2})?)\s+\[([\d\.]+[KMGTP]?i?B?\/s)\]\s+\[.*?\]\s+(\d+)%\s+ETA\s+(\d{1,2}:\d{2}(:\d{2})?)/';

        $parseTimeToSeconds = function (string $time): int {
            // e.g. "0:02:11" => 131, "01:05" => 65
            $parts = array_reverse(explode(':', $time));
            $seconds = 0;
            foreach ($parts as $i => $p) {
                $seconds += (int)$p * (60 ** $i);
            }
            return $seconds;
        };

        // Keep reading until the process finishes
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            // Read stdout
            $chunkOut = fread($pipes[1], 8192);
            if ($chunkOut !== false && $chunkOut !== '') {
                $stdOutBuffer .= $chunkOut;
                // Split on \r or \n. Usually real output uses \n, but let's handle both
                $lines = preg_split("/\r\n|\n|\r/", $stdOutBuffer);
                // Keep the last partial piece
                $stdOutBuffer = array_pop($lines);
                // The rest are complete lines
                foreach ($lines as $line) {
                    // Not expecting progress on stdout (since `pv` writes progress to stderr)
                    $stdOut .= $line . "\n";
                }
            }

            // Read stderr
            $chunkErr = fread($pipes[2], 8192);
            if ($chunkErr !== false && $chunkErr !== '') {
                $stdErrBuffer .= $chunkErr;
                // `pv` uses carriage returns to update progress, so parse by \r or \n
                $lines = preg_split("/\r\n|\n|\r/", $stdErrBuffer);
                $stdErrBuffer = array_pop($lines);

                foreach ($lines as $line) {
                    $lineTrim = trim($line);
                    if ($progressCallback && $lineTrim !== '' && preg_match($progressRegex, $lineTrim, $m)) {
                        // $m[1] => transferred, $m[2] => elapsed, $m[4] => speed, $m[5] => percent, $m[6] => ETA
                        // Because capturing parentheses appear more than once, let's get them carefully:
                        //   1 => e.g. "340MiB"
                        //   2 => e.g. "0:00:07"
                        //   3 => e.g. ":07" or missing if not matched
                        //   4 => e.g. "60.8MiB/s"
                        //   5 => e.g. "5"
                        //   6 => e.g. "0:02:11"
                        $transferredSize = $m[1];
                        $elapsedTime     = $parseTimeToSeconds($m[2]);
                        $speed           = $m[4];
                        $percent         = (int)$m[5];
                        $eta             = $parseTimeToSeconds($m[6]);

                        $progressCallback($percent, $eta, $elapsedTime, $speed, $transferredSize);
                    } else {
                        // Not a recognized progress line -> treat as normal stderr
                        $stdErr .= $line . "\n";
                    }
                }
            }

            // Sleep briefly to avoid busy-waiting
            usleep(200000);
        }

        // Process ended; read any last data
        // Possibly the command ended very quickly and there's leftover data in buffers.
        // Let’s do a final flush.
        $finalOut = stream_get_contents($pipes[1]);
        if ($finalOut !== false) {
            $stdOutBuffer .= $finalOut;
        }
        $finalErr = stream_get_contents($pipes[2]);
        if ($finalErr !== false) {
            $stdErrBuffer .= $finalErr;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        // Final parse of leftover buffers
        if ($stdOutBuffer !== '') {
            $stdOut .= $stdOutBuffer . "\n";
        }

        if ($stdErrBuffer !== '') {
            // This might contain partial progress lines or real errors
            $lines = preg_split("/\r\n|\n|\r/", $stdErrBuffer);
            foreach ($lines as $line) {
                $lineTrim = trim($line);
                if ($progressCallback && $lineTrim !== '' && preg_match($progressRegex, $lineTrim, $m)) {
                    $transferredSize = $m[1];
                    $elapsedTime     = $parseTimeToSeconds($m[2]);
                    $speed           = $m[4];
                    $percent         = (int)$m[5];
                    $eta             = $parseTimeToSeconds($m[6]);

                    $progressCallback($percent, $transferredSize, $elapsedTime, $speed, $eta);
                } elseif ($lineTrim !== '') {
                    $stdErr .= $line . "\n";
                }
            }
        }

        // Get the real exit code
        $exitCode = proc_close($process);

        return [ (int)$exitCode, rtrim($stdOut), rtrim($stdErr) ];
    }
}

if (!function_exists('remote_ssh_exec')) {
    /**
     * Executes a shell command on a remote host via SSH.
     *
     * @param string $host The remote host to connect to.
     * @param string $user The username to use for the SSH connection.
     * @param int $port The port to use for the SSH connection.
     * @param string $command The command to execute on the remote host.
     *
     * @throws \RuntimeException If the SSH command fails.
     *
     * @return string The output of the command.
     */
    function remote_ssh_exec(string $host, string $user, int $port, string $command) : string
    {
        // SSH command to execute the provided command remotely
        $sshCommand = sprintf(
            'timeout 30 ssh -p %d -o ConnectTimeout=10 %s@%s "%s"',
            $port,
            escapeshellarg($user),
            escapeshellarg($host),
            str_replace('"', '\"', $command)
        );

        $output = wrap_exec($sshCommand, "SSH command failed.");
        return $output;
    }
}

if (!function_exists('binary_exists')) {
    /**
     * Checks if a binary exists in the system.
     *
     * @param string $binary The binary to check.
     *
     * @return bool True if the binary exists, false otherwise.
     */
    function binary_exists(string $binary): bool
    {
        $binary = escapeshellarg($binary);
        return !empty(shell_exec("command -v {$binary}"));
    }
}

if (! function_exists('wrap_exec')) {
    /**
     * Executes a shell command and returns the string output.
     * ATTENTION: the sanitization of the command is up to the caller. This function does not escape the command.
     *
     * @param string $command
     * @param string|null $runtimeExceptionMessage
     *
     * @throws \RuntimeException if the command fails and a runtime exception message is provided
     * @return string - The output of the command
     */
    function wrap_exec(string $command, ?string $runtimeExceptionMessage = null) : string
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException('The exec function is not available on this system.');
        }
        
        $output = [];
        $returnCode = 0;
        exec("{$command} 2>/dev/null", $output, $returnCode);
        $output = implode(PHP_EOL, array_map('trim', $output));

        if ($runtimeExceptionMessage && $returnCode != 0) {
            throw new \RuntimeException("Exec of \"{$command}\" command failed: {$runtimeExceptionMessage}. Raw output: {$output}");
        }

        if ($returnCode != 0) {
            return '';
        }

        return $output;
    }
}

if (!function_exists('self_spawn')) {
    /**
     * Spawns another Laravel Zero command, passing the given arguments.
     * It is assumed that the command returns a JSON string, that can be parsed into an array.
     *
     * @param string $command
     * @param array $arguments
     * @param string|null $execString The command string that was executed
     *
     * @throws \RuntimeException if the JSON output cannot be parsed
     * @return mixed
     */
    function self_spawn(string $command, array $arguments, ?string &$execString = null) : mixed
    {
        $argString = '';
        foreach ($arguments as $key => $value) {
            $key = trim($key, '-');
            if ($key && $value) {
                $value = escapeshellarg($value);
                $argString .= "--{$key}={$value} ";
            } elseif ($key) {
                $argString .= "--{$key} ";
            } elseif ($value) {
                $value = escapeshellarg($value);
                $argString .= "{$value} ";
            }
        }

        $argString = trim($argString);

        $phpExec = "/usr/bin/php -d memory_limit=-1";
        $cwd = rtrim(getcwd(), DIRECTORY_SEPARATOR);
        $commandName = basename($_SERVER['argv'][0]);
        $fullExecutablePath = implode(DIRECTORY_SEPARATOR, [$cwd, $commandName]);
        $exec = "{$phpExec} {$fullExecutablePath} {$command} {$argString}";

        if ($execString !== null) {
            $execString = $exec;
        }

        $output = wrap_exec($exec, "Failed to spawn the '{$phpExec} {$fullExecutablePath} {$command}' command.");
        $decoded = @unserialize($output);

        if ($decoded === false) {
            throw new \RuntimeException("Failed to parse the serialized output of the '{$phpExec} {$fullExecutablePath} {$command}' command.");
        }

        return $decoded;
    }
}

if (!function_exists('system_hostname')) {
    /**
     * Returns the system hostname.
     *
     * @return string
     */
    function system_hostname(): string
    {
        // Preferred built-in:
        if (function_exists('gethostname')) {
            $name = gethostname();
            if (!empty($name)) {
                return $name;
            }
        }

        // Fallback to shell command:
        return trim(shell_exec('hostname'));
    }
}

if (!function_exists('human_filesize')) {
    /**
     * Reads a file's size and returns a human-readable string.
     *
     * @param mixed $input The file to read, or the size in bytes.
     *
     * @return string
     */
    function human_filesize($input): string
    {
        if (is_numeric($input)) {
            $size = $input;
        } elseif (is_string($input) && file_exists($input)) {
            $size = filesize($input);
        } else {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        for ($i = 0; $size > 1024; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
}

if (!function_exists('multi_explode')) {
    /**
     * Explodes a string by multiple delimiters.
     *
     * @param array $delimiters The delimiters to use.
     * @param string $string The string to explode.
     *
     * @return array
     */
    function multi_explode(array $delimiters, string $string): array
    {
        // Start with an array containing the entire string as one element.
        return array_reduce($delimiters, function (array $carry, string $delimiter) {
            $result = [];
            // For each piece in the existing array, explode by the next delimiter,
            // then merge those pieces back into a new array.
            foreach ($carry as $part) {
                $exploded = explode($delimiter, $part);
                $result   = array_merge($result, $exploded);
            }
            return $result;
        }, [$string]);
    }
}
