<?php

declare(strict_types=1);

namespace Tests\Unit\Application\System;

use Mfonte\Arkhive\Application\System\CacheDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheDirectoryTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string|null}>
     */
    public static function cases(): iterable
    {
        yield 'systemd CacheDirectory=' => [['CACHE_DIRECTORY' => '/var/cache/arkhive'], '/var/cache/arkhive/restic'];
        yield 'several directories: the first' => [['CACHE_DIRECTORY' => '/var/cache/arkhive:/var/cache/other'], '/var/cache/arkhive/restic'];
        yield 'trailing slash' => [['CACHE_DIRECTORY' => '/var/cache/arkhive/'], '/var/cache/arkhive/restic'];
        yield 'the root directory' => [['CACHE_DIRECTORY' => '/'], '/restic'];
        yield 'relative CACHE_DIRECTORY is ignored' => [['CACHE_DIRECTORY' => 'cache', 'HOME' => '/root'], null];
        yield 'an exported RESTIC_CACHE_DIR wins' => [['CACHE_DIRECTORY' => '/var/cache/arkhive', 'RESTIC_CACHE_DIR' => '/srv/cache'], null];
        yield 'an empty RESTIC_CACHE_DIR does not count' => [['CACHE_DIRECTORY' => '/var/cache/arkhive', 'RESTIC_CACHE_DIR' => ''], '/var/cache/arkhive/restic'];
        yield 'outside systemd restic chooses' => [['HOME' => '/root', 'XDG_CACHE_HOME' => '/srv/xdg'], null];
        yield 'HOME alone' => [['HOME' => '/root'], null];
        yield 'XDG_CACHE_HOME alone' => [['XDG_CACHE_HOME' => '/srv/xdg'], null];
        yield 'neither: under the account home' => [[], '/home/backup/.cache/restic'];
        yield 'empty ones do not count' => [['HOME' => '', 'XDG_CACHE_HOME' => ''], '/home/backup/.cache/restic'];
        yield 'systemd first, even without HOME' => [['CACHE_DIRECTORY' => '/var/cache/arkhive'], '/var/cache/arkhive/restic'];
        yield 'RESTIC_CACHE_DIR first, even without HOME' => [['RESTIC_CACHE_DIR' => '/srv/cache'], null];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('cases')]
    public function testTheResticCacheDirectory(array $environment, ?string $expected): void
    {
        self::assertSame($expected, CacheDirectory::restic($environment, '/home/backup'));
    }

    public function testWithoutHomeAnUnusableAccountHomeLeavesItToRestic(): void
    {
        self::assertNull(CacheDirectory::restic([], null));
        self::assertNull(CacheDirectory::restic([], '/'));
        self::assertNull(CacheDirectory::restic([], 'relative'));
        self::assertSame('/root/.cache/restic', CacheDirectory::restic([], '/root/'));
    }

    public function testTheAccountHomeComesFromThePasswordDatabase(): void
    {
        $account = posix_getpwuid(posix_geteuid());
        self::assertIsArray($account);

        self::assertSame($account['dir'] === '' ? null : $account['dir'], CacheDirectory::accountHome());
    }
}
