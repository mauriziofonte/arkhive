<?php

declare(strict_types=1);

namespace Tests\e2e;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CliResult;
use Tests\Support\TempDir;

/**
 * Base class for the end-to-end suite: everything comes from the E2E_* variables exported by run.sh,
 * and ArkHive itself is always the built PHAR.
 */
abstract class E2eTestCase extends TestCase
{
    use TempDir;

    protected static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            self::fail("{$name} is not set: run the suite through tests/e2e/run.sh");
        }

        return $value;
    }

    /**
     * PATH for ArkHive under test: the pinned restic first, then the system.
     */
    protected static function path(): string
    {
        return dirname(self::env('E2E_RESTIC')) . ':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    }

    /**
     * A fresh repository on the SFTP server, reached through RESTIC_SFTP_COMMAND (non-standard port).
     *
     * @return array{string, string} [RESTIC_REPOSITORY, RESTIC_SFTP_COMMAND]
     */
    protected static function sftpRepository(): array
    {
        $user = self::env('E2E_SFTP_USER');
        $host = self::env('E2E_SFTP_HOST');
        $repository = sprintf('sftp:%s@%s:%s/%s', $user, $host, self::env('E2E_SFTP_PATH'), bin2hex(random_bytes(6)));
        $command = sprintf(
            'ssh -i %s -p %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes -o ServerAliveInterval=5 -o ServerAliveCountMax=3 -o LogLevel=ERROR %s@%s -s sftp',
            self::env('E2E_SSH_KEY'),
            self::env('E2E_SFTP_PORT'),
            $user,
            $host,
        );

        return [$repository, $command];
    }

    /**
     * Creates the repository with `arkhive init` (spec §13.2 step 2), from a minimal configuration.
     */
    protected function arkhiveInit(string $dir, string $repository, ?string $sftpCommand): void
    {
        $values = ['BACKUP_PATHS' => $dir, 'RESTIC_REPOSITORY' => $repository, 'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE')];
        if ($sftpCommand !== null) {
            $values['RESTIC_SFTP_COMMAND'] = $sftpCommand;
        }
        $config = $this->writeConfig("{$dir}/init-config", $values);
        $init = self::arkhive(['init', '--config', $config]);
        self::assertSame(0, $init->exitCode, $init->output());
        self::assertStringContainsString("success Created the restic repository {$repository}", $init->stderr);
        unlink($config);
    }

    /**
     * Runs restic directly (for setup and for checking what ArkHive did).
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    protected static function restic(array $args, string $repository, ?string $sftpCommand, array $env = []): CliResult
    {
        $options = $sftpCommand === null ? [] : ['-o', "sftp.command={$sftpCommand}"];

        return self::exec([self::env('E2E_RESTIC'), ...$options, ...$args], [
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            ...$env,
        ]);
    }

    /**
     * Runs the PHAR without a terminal, in a minimal environment.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    protected static function arkhive(array $args, array $env = [], ?string $cwd = null): CliResult
    {
        return self::exec([PHP_BINARY, self::env('E2E_PHAR'), ...$args], $env, $cwd);
    }

    /**
     * Starts the PHAR in the background.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{resource, array<int, resource>}
     */
    protected static function startArkhive(array $args, array $env = []): array
    {
        $process = proc_open([PHP_BINARY, self::env('E2E_PHAR'), ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::environment($env));
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start the PHAR');
        }

        return [$process, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $started
     */
    protected static function finish(array $started): CliResult
    {
        [$process, $pipes] = $started;
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return new CliResult($status, $stdout, $stderr);
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    protected static function exec(array $argv, array $env = [], ?string $cwd = null, string $stdin = ''): CliResult
    {
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, self::environment($env));
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $argv));
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new CliResult(proc_close($process), $stdout, $stderr);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array<string, string>
     */
    protected static function environment(array $env): array
    {
        return [
            'PATH' => self::path(),
            'HOME' => (string) getenv('HOME'),
            'LC_ALL' => 'C',
            ...$env,
        ];
    }

    /**
     * Writes a 0600 configuration file.
     *
     * @param array<string, string> $values
     */
    protected function writeConfig(string $path, array $values): string
    {
        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= $key . "='" . str_replace("'", '', $value) . "'\n";
        }
        file_put_contents($path, $lines);
        chmod($path, 0600);

        return $path;
    }

    /**
     * Polls until $condition is true or $seconds pass.
     */
    protected static function waitFor(callable $condition, float $seconds = 30.0): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(20000);
        }

        return false;
    }
}
