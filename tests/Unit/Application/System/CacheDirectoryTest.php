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
        yield 'relative CACHE_DIRECTORY is ignored' => [['CACHE_DIRECTORY' => 'cache'], null];
        yield 'an exported RESTIC_CACHE_DIR wins' => [['CACHE_DIRECTORY' => '/var/cache/arkhive', 'RESTIC_CACHE_DIR' => '/srv/cache'], null];
        yield 'an empty RESTIC_CACHE_DIR does not count' => [['CACHE_DIRECTORY' => '/var/cache/arkhive', 'RESTIC_CACHE_DIR' => ''], '/var/cache/arkhive/restic'];
        yield 'outside systemd restic chooses' => [['HOME' => '/root', 'XDG_CACHE_HOME' => '/srv/xdg'], null];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('cases')]
    public function testTheResticCacheDirectory(array $environment, ?string $expected): void
    {
        self::assertSame($expected, CacheDirectory::restic($environment));
    }
}
