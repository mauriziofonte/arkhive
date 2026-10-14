<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Repository;

use InvalidArgumentException;
use Mfonte\Arkhive\Domain\Repository\ResticCapabilities;
use Mfonte\Arkhive\Domain\Repository\ResticVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResticVersionTest extends TestCase
{
    public function testParsesTheVersionCommandAndPlainNumbers(): void
    {
        self::assertSame('0.11.0', (string) ResticVersion::parse("restic 0.11.0 compiled with go1.15.9 on linux/amd64\n"));
        self::assertSame('0.19.1', (string) ResticVersion::parse('0.19.1'));
        self::assertSame('1.2.30', (string) ResticVersion::parse('restic 1.2.30-dev (compiled manually)'));
        self::assertNull(ResticVersion::parse('rustic 0.9.0'));
        self::assertNull(ResticVersion::parse('restic 0.19'));
    }

    public function testOfRefusesWhatIsNotAVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResticVersion::of('latest');
    }

    public function testComparisonIsNumericNotTextual(): void
    {
        self::assertTrue(ResticVersion::of('0.16.10')->atLeast('0.16.9'));
        self::assertFalse(ResticVersion::of('0.9.6')->atLeast('0.11.0'));
        self::assertTrue(ResticVersion::of('1.0.0')->atLeast('0.19.1'));
        self::assertTrue(ResticVersion::of('0.16.1')->atLeast('0.16.1'));
        self::assertSame(0, ResticVersion::of('0.14.0')->compare(ResticVersion::of('0.14.0')));
        self::assertSame(-1, ResticVersion::of('0.14.0')->compare(ResticVersion::of('0.14.1')));
        self::assertSame(1, ResticVersion::of('0.15.0')->compare(ResticVersion::of('0.14.9')));
    }

    /**
     * @return iterable<string, array{string, array<string, bool>}>
     */
    public static function versions(): iterable
    {
        $none = ['supported' => true, 'percentSubset' => false, 'sizeSubset' => false, 'compression' => false, 'jsonInit' => false, 'sparseRestore' => false, 'jsonRestore' => false, 'retryLock' => false, 'sftpArgs' => false, 'dedicatedExitCodes' => false, 'jsonCheck' => false];
        yield '0.10.0 (too old)' => ['0.10.0', ['supported' => false] + $none];
        yield '0.11.0 Debian 11' => ['0.11.0', $none];
        yield '0.12.1 Ubuntu 22.04' => ['0.12.1', ['percentSubset' => true] + $none];
        yield '0.14.0 Debian 12' => ['0.14.0', ['percentSubset' => true, 'sizeSubset' => true, 'compression' => true] + $none];
        yield '0.15.2' => ['0.15.2', ['percentSubset' => true, 'sizeSubset' => true, 'compression' => true, 'jsonInit' => true, 'sparseRestore' => true] + $none];
        yield '0.16.0' => ['0.16.0', ['percentSubset' => true, 'sizeSubset' => true, 'compression' => true, 'jsonInit' => true, 'sparseRestore' => true, 'jsonRestore' => true, 'retryLock' => true] + $none];
        yield '0.16.4 Ubuntu 24.04' => ['0.16.4', ['percentSubset' => true, 'sizeSubset' => true, 'compression' => true, 'jsonInit' => true, 'sparseRestore' => true, 'jsonRestore' => true, 'retryLock' => true, 'sftpArgs' => true] + $none];
        yield '0.17.3' => ['0.17.3', ['percentSubset' => true, 'sizeSubset' => true, 'compression' => true, 'jsonInit' => true, 'sparseRestore' => true, 'jsonRestore' => true, 'retryLock' => true, 'sftpArgs' => true, 'dedicatedExitCodes' => true] + $none];
        yield '0.18.0 Debian 13' => ['0.18.0', array_map(static fn (): bool => true, $none)];
        yield '0.19.1' => ['0.19.1', array_map(static fn (): bool => true, $none)];
    }

    /**
     * @param array<string, bool> $expected
     */
    #[DataProvider('versions')]
    public function testCapabilitiesFollowTheReleaseThatIntroducedThem(string $version, array $expected): void
    {
        $capabilities = ResticCapabilities::of($version);
        $actual = [];
        foreach (array_keys($expected) as $feature) {
            $actual[$feature] = $capabilities->{$feature}();
        }

        self::assertSame($expected, $actual);
        self::assertSame($version, (string) $capabilities->version);
    }

    public function testLimitationsAreNamedForDoctor(): void
    {
        self::assertSame([
            'no compression (repository format 1; format 2 needs restic 0.14.0 or newer)',
            'restores write sparse files in full',
            'no restore progress',
        ], ResticCapabilities::of('0.11.0')->limitations());
        self::assertSame(['restores write sparse files in full', 'no restore progress'], ResticCapabilities::of('0.14.0')->limitations());
        self::assertSame(['no restore progress'], ResticCapabilities::of('0.15.1')->limitations());
        self::assertSame([], ResticCapabilities::of('0.16.0')->limitations());
    }
}
