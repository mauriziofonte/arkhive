<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Backup;

use InvalidArgumentException;
use Mfonte\Arkhive\Domain\Backup\DumpLimits;
use PHPUnit\Framework\TestCase;

final class DumpLimitsTest extends TestCase
{
    public function testTheDefaultStopsAStallButNotALongDump(): void
    {
        $limits = new DumpLimits();

        self::assertNull($limits->timeout);
        self::assertSame(300, $limits->stallTimeout);
    }

    public function testZeroFromTheConfigurationMeansNoLimit(): void
    {
        $limits = DumpLimits::fromSeconds(0, 0);
        self::assertNull($limits->timeout);
        self::assertNull($limits->stallTimeout);

        $limits = DumpLimits::fromSeconds(3600, 120);
        self::assertSame(3600, $limits->timeout);
        self::assertSame(120, $limits->stallTimeout);
    }

    public function testALimitIsPositive(): void
    {
        foreach ([[0, null], [null, 0], [-5, 300]] as [$timeout, $stall]) {
            try {
                new DumpLimits($timeout, $stall);
                self::fail('accepted ' . var_export([$timeout, $stall], true));
            } catch (InvalidArgumentException $e) {
                self::assertSame('A dump time limit is a positive number of seconds, or none', $e->getMessage());
            }
        }
    }
}
