<?php

declare(strict_types=1);

namespace Tests\Compat;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CliResult;
use Tests\Support\TempDir;

/**
 * Base class of the restic compatibility suite (docs/2.1-PLAN.md §3): ArkHive's real commands
 * against a local repository, with the restic binary named by ARKHIVE_TEST_RESTIC (any release
 * from 0.11.0). No Docker, no network; ARKHIVE_TEST_PHAR runs a built PHAR instead of the sources.
 */
abstract class CompatTestCase extends TestCase
{
    use TempDir;

    protected static function restic(): string
    {
        $binary = getenv('ARKHIVE_TEST_RESTIC');
        if ($binary === false || $binary === '') {
            self::markTestSkipped('ARKHIVE_TEST_RESTIC is not set: point it at the restic binary to test');
        }
        if (!is_executable($binary)) {
            self::fail("ARKHIVE_TEST_RESTIC {$binary} is not an executable file");
        }

        return $binary;
    }

    /**
     * "0.11.0", from `restic version`.
     */
    protected static function resticVersion(): string
    {
        $result = self::exec([self::restic(), 'version']);
        if (preg_match('/^restic (\d+\.\d+\.\d+)/', $result->stdout, $match) !== 1) {
            self::fail('cannot read the version of ' . self::restic() . ': ' . $result->output());
        }

        return $match[1];
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    protected static function arkhive(array $args, array $env = []): CliResult
    {
        $phar = getenv('ARKHIVE_TEST_PHAR');
        $entry = $phar === false || $phar === '' ? dirname(__DIR__, 2) . '/arkhive' : $phar;

        return self::exec([PHP_BINARY, $entry, ...$args], $env);
    }

    /**
     * Runs restic itself, to check what ArkHive did.
     *
     * @param list<string> $args
     */
    protected static function resticRun(array $args, string $repository, string $passwordFile): CliResult
    {
        return self::exec([self::restic(), ...$args], ['RESTIC_REPOSITORY' => $repository, 'RESTIC_PASSWORD_FILE' => $passwordFile]);
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    protected static function exec(array $argv, array $env = []): CliResult
    {
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'LC_ALL' => 'C',
            ...$env,
        ];
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $argv));
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new CliResult(proc_close($process), $stdout, $stderr);
    }

    /**
     * Writes a 0600 configuration file.
     *
     * @param array<string, string> $values
     */
    protected static function writeConfig(string $path, array $values): string
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
     * A small tree with text, binary, sparse-friendly and nested files.
     */
    protected static function sourceTree(string $dir): void
    {
        mkdir("{$dir}/site/assets", 0755, true);
        file_put_contents("{$dir}/site/index.html", "<h1>ArkHive compat</h1>\n");
        file_put_contents("{$dir}/site/assets/logo.bin", random_bytes(70000));
        file_put_contents("{$dir}/site/assets/zeros.img", str_repeat("\0", 1 << 20));
        file_put_contents("{$dir}/site/with space.txt", "a file name with a space\n");
        file_put_contents("{$dir}/site/assets/debug.log", "excluded by pattern\n");
        mkdir("{$dir}/cache", 0755);
        file_put_contents("{$dir}/cache/session.tmp", "excluded\n");
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(CliResult $result): array
    {
        $data = json_decode(trim($result->stdout), true);
        self::assertIsArray($data, 'stdout is JSON: ' . $result->output());

        /** @var array<string, mixed> $data */
        return $data;
    }
}
