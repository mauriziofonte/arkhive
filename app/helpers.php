<?php

if (!function_exists('proc_exec')) {
    /**
     * Executes a shell command, captures output, errors, and returns the exit code.
     * ATTENTION: the sanitization of the command is up to the caller. This function does not escape the command.
     *
     * This function utilizes `proc_open` to execute a shell command, allowing full access
     * to standard input, output, and error streams. It waits for the process to complete
     * and returns the exit code, standard output, and standard error.
     *
     * @param string $command The shell command to execute.
     *
     * @throws \RuntimeException If the process could not be created.
     *
     * @return array{int, string, string} An array containing:
     *     - int: The exit code of the command.
     *     - string: The standard output (stdout) of the command.
     *     - string: The standard error (stderr) of the command.
     */
    function proc_exec(string $command) : array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('The proc_open function is not available on this system.');
        }
        
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not create a valid process');
        }

        // This will prevent to program from continuing until the processes is complete
        // Note: exitcode is created on the final loop here
        $status = proc_get_status($process);
        while ($status['running']) {
            $status = proc_get_status($process);
        }

        $stdOutput = stream_get_contents($pipes[1]);
        $stdError  = stream_get_contents($pipes[2]);

        proc_close($process);

        return [intval($status['exitcode']), trim($stdOutput), trim($stdError)];
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
            'ssh -p %d %s@%s "%s"',
            $port,
            escapeshellarg($user),
            escapeshellarg($host),
            str_replace('"', '\"', $command)
        );

        // Execute using proc_exec helper
        [$exitCode, $output, $error] = proc_exec($sshCommand);

        if ($exitCode !== 0) {
            throw new \RuntimeException("SSH command failed with exit code {$exitCode}: {$error}");
        }

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
        return !empty(shell_exec("command -v $binary"));
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
