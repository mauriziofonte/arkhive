<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schedule;

use Mfonte\Arkhive\Domain\Schedule\CronTable;
use Mfonte\Arkhive\Domain\Schedule\Schedule;
use Mfonte\Arkhive\Domain\Schedule\SystemdUnits;
use PHPUnit\Framework\TestCase;

/**
 * contrib/ holds what `arkhive schedule` prints for /usr/local/bin/arkhive and /etc/arkhive/config:
 * the examples cannot drift from the generator.
 */
final class ContribTest extends TestCase
{
    private const CONTRIB = __DIR__ . '/../../../../contrib';

    public function testTheSystemdExamplesAreTheGeneratedUnits(): void
    {
        $expected = [];
        foreach (SystemdUnits::render(self::schedule()) as $path => $unit) {
            $expected[basename($path)] = $unit;
        }
        $actual = [];
        foreach (glob(self::CONTRIB . '/systemd/*') ?: [] as $file) {
            $actual[basename($file)] = (string) file_get_contents($file);
        }
        ksort($expected);

        self::assertSame($expected, $actual);
    }

    public function testTheCronExampleIsTheGeneratedTable(): void
    {
        self::assertSame(CronTable::render(self::schedule()), file_get_contents(self::CONTRIB . '/cron/arkhive'));
    }

    private static function schedule(): Schedule
    {
        return new Schedule(['/usr/local/bin/arkhive'], '/etc/arkhive/config', 'root', 2, 30, '/var/log/arkhive.log');
    }
}
