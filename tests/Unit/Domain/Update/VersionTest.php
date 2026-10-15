<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use Mfonte\Arkhive\Domain\Update\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('2.0.1', Version::normalize('v2.0.1'));
        self::assertSame('2.0.0-rc.1', Version::normalize('V2.0.0-rc.1'));
        self::assertSame('1.7.1', Version::normalize(' 1.7.1 '));
        foreach (['latest', 'v2', '2.0', 'v2.0.1.4', '2.0.0-', '2.0.0-rc_1', ''] as $invalid) {
            self::assertNull(Version::normalize($invalid), $invalid);
        }
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function comparisons(): iterable
    {
        yield 'patch' => ['2.0.1', '2.0.0', 1];
        yield 'with v' => ['v2.0.0', '2.0.0', 0];
        yield 'rc before release' => ['2.0.0-rc.1', '2.0.0', -1];
        yield 'rc ordering is numeric' => ['2.0.0-rc.10', '2.0.0-rc.2', 1];
        yield 'beta before rc' => ['2.0.0-beta.3', '2.0.0-rc.1', -1];
        yield 'minor beats patch' => ['2.1.0', '2.0.9', 1];
        yield 'major' => ['1.7.1', '2.0.0-rc.1', -1];
    }

    #[DataProvider('comparisons')]
    public function testCompare(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, Version::compare($a, $b));
        self::assertSame(-$expected, Version::compare($b, $a));
    }

    public function testMajorAndPrerelease(): void
    {
        self::assertSame(2, Version::major('v2.0.0-rc.1'));
        self::assertSame(1, Version::major('1.7.1'));
        self::assertTrue(Version::isPrerelease('v2.0.0-rc.1'));
        self::assertFalse(Version::isPrerelease('2.0.0'));
    }
}
