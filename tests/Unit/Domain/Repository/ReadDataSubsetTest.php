<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Repository;

use Mfonte\Arkhive\Domain\Repository\ReadDataSubset;
use Mfonte\Arkhive\Domain\Repository\ResticCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadDataSubsetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function subsets(): iterable
    {
        $gib = 1024 ** 3;
        yield 'percent on 0.19' => ['5%', '0.19.1', $gib, '5%'];
        yield 'size on 0.14' => ['500M', '0.14.0', $gib, '500M'];
        yield 'group anywhere' => ['3/7', '0.11.0', $gib, '3/7'];
        yield 'percent on 0.12' => ['2.5%', '0.12.1', $gib, '2.5%'];
        yield 'percent on 0.11 is one group of 20' => ['5%', '0.11.0', $gib, '1/20'];
        yield 'small percent on 0.11' => ['2.5%', '0.11.0', $gib, '1/40'];
        yield 'large percent on 0.11' => ['30%', '0.11.0', $gib, '1/3'];
        yield 'everything on 0.11' => ['100%', '0.11.0', $gib, '1/1'];
        yield 'size on 0.12 is a percentage of the repository' => ['256M', '0.12.1', $gib, '25%'];
        yield 'size with an odd fraction' => ['100M', '0.12.1', 3 * $gib, '3.26%'];
        yield 'size larger than the repository' => ['2G', '0.12.1', $gib, '100%'];
        yield 'size in bytes' => ['1', '0.12.1', $gib, '0.01%'];
        yield 'size on 0.11 is a group' => ['100M', '0.11.0', $gib, '1/10'];
        yield 'size of an empty repository' => ['500M', '0.11.0', 0, '1/1'];
        yield 'zero percent on 0.11' => ['0%', '0.11.0', $gib, '1/100'];
    }

    #[DataProvider('subsets')]
    public function testTheSubsetFitsTheInstalledRestic(string $subset, string $version, int $repositoryBytes, string $expected): void
    {
        self::assertSame($expected, ReadDataSubset::adapt($subset, ResticCapabilities::of($version), static fn (): int => $repositoryBytes, 0));
    }

    public function testTheSeedRotatesTheGroupSoThatEveryPackIsReadOverTime(): void
    {
        $capabilities = ResticCapabilities::of('0.11.0');
        $groups = [];
        foreach (range(0, 39) as $seed) {
            $groups[] = ReadDataSubset::adapt('5%', $capabilities, static fn (): int => 0, $seed);
        }

        self::assertCount(20, array_unique($groups));
        self::assertSame('20/20', $groups[19]);
        self::assertSame('1/20', $groups[20]);
        self::assertSame('8/20', ReadDataSubset::adapt('5%', $capabilities, static fn (): int => 0, -7));
    }

    public function testTheRepositorySizeIsAskedOnlyWhenNeeded(): void
    {
        $asked = 0;
        $size = static function () use (&$asked): int {
            $asked++;

            return 1024;
        };

        ReadDataSubset::adapt('5%', ResticCapabilities::of('0.11.0'), $size, 1);
        ReadDataSubset::adapt('500M', ResticCapabilities::of('0.14.0'), $size, 1);
        self::assertSame(0, $asked);
        ReadDataSubset::adapt('500M', ResticCapabilities::of('0.12.1'), $size, 1);
        self::assertSame(1, $asked);
    }
}
