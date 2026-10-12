<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Integration tests run the real restic binary. Without it they are skipped, unless
 * ARKHIVE_REQUIRE_RESTIC=1 (CI), where a missing binary is a failure.
 */
trait RequiresRestic
{
    protected static function resticBinary(): string
    {
        $configured = getenv('ARKHIVE_TEST_RESTIC');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("{$dir}/restic") && is_executable("{$dir}/restic")) {
                return "{$dir}/restic";
            }
        }
        if (getenv('ARKHIVE_REQUIRE_RESTIC') === '1') {
            self::fail('restic is required (ARKHIVE_REQUIRE_RESTIC=1) but was not found on PATH');
        }
        self::markTestSkipped('restic not found on PATH');
    }

    /**
     * Runs restic with the given arguments and returns [exit code, stdout, stderr].
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    protected static function restic(array $args, array $env = []): array
    {
        $process = proc_open(
            array_merge([self::resticBinary()], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'RESTIC_CACHE_DIR' => sys_get_temp_dir() . '/arkhive-test-restic-cache'],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
