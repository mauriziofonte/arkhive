<?php

declare(strict_types=1);

namespace Tests\Integration\Schedule;

use Mfonte\Arkhive\Domain\Schedule\Schedule;
use Mfonte\Arkhive\Domain\Schedule\SystemdUnits;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * The generated units pass `systemd-analyze verify`: every directive is known and parses (a bad
 * value is only a warning there, so any line naming a unit file fails the test).
 */
final class SystemdVerifyTest extends TestCase
{
    use TempDir;

    public function testTheUnitsVerify(): void
    {
        if (!is_executable('/usr/bin/systemd-analyze')) {
            self::markTestSkipped('systemd-analyze is not installed');
        }
        $dir = $this->tempDir();
        // a config path that needs quoting and escaping; the executable must exist for verify
        $schedule = new Schedule([PHP_BINARY, dirname(__DIR__, 3) . '/arkhive'], '/etc/my "conf"/100% $x', 'root', 4, 45, '/var/log/arkhive.log', ['/srv/dumps', '/srv/my repo/100%']);
        $files = [];
        foreach (SystemdUnits::render($schedule) as $path => $unit) {
            $files[] = $file = $dir . '/' . basename($path);
            file_put_contents($file, $unit);
        }

        $process = proc_open(['/usr/bin/systemd-analyze', 'verify', '--man=no', ...$files], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, (string) $output);
        self::assertStringNotContainsString($dir, (string) $output, 'no warning about the units');
    }
}
