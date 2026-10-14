<?php

declare(strict_types=1);

namespace Tests\Unit\Application\System;

use Mfonte\Arkhive\Application\System\StateDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateDirectoryTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, int, string|null}>
     */
    public static function cases(): iterable
    {
        $home = ['HOME' => '/home/ops'];
        yield 'systemd StateDirectory= wins, even as root' => [['STATE_DIRECTORY' => '/var/lib/arkhive-web', ...$home], 0, '/var/lib/arkhive-web'];
        yield 'systemd with several directories: the first' => [['STATE_DIRECTORY' => '/var/lib/arkhive:/var/lib/other'], 1000, '/var/lib/arkhive'];
        yield 'systemd value with a trailing slash' => [['STATE_DIRECTORY' => '/var/lib/arkhive/'], 1000, '/var/lib/arkhive'];
        yield 'relative STATE_DIRECTORY is ignored' => [['STATE_DIRECTORY' => 'state', ...$home], 1000, '/home/ops/.local/state/arkhive'];
        yield 'root' => [$home, 0, '/var/lib/arkhive'];
        yield 'user with XDG_STATE_HOME' => [['XDG_STATE_HOME' => '/srv/state/', ...$home], 1000, '/srv/state/arkhive'];
        yield 'relative XDG_STATE_HOME is ignored' => [['XDG_STATE_HOME' => 'state', ...$home], 1000, '/home/ops/.local/state/arkhive'];
        yield 'user' => [$home, 1000, '/home/ops/.local/state/arkhive'];
        yield 'user without a home' => [[], 1000, null];
        yield 'user with a relative home' => [['HOME' => 'ops'], 1000, null];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('cases')]
    public function testTheStateDirectory(array $environment, int $uid, ?string $expected): void
    {
        self::assertSame($expected, StateDirectory::resolve($environment, $uid));
    }

    public function testTheUnitsStateDirectoryOfThisUserIsFound(): void
    {
        $home = ['HOME' => '/home/ops'];

        self::assertSame('/var/lib/arkhive', StateDirectory::resolve($home, 1000, 1000), 'created by systemd for User=ops');
        self::assertSame('/home/ops/.local/state/arkhive', StateDirectory::resolve($home, 1000, 0), 'root\'s');
        self::assertSame('/home/ops/.local/state/arkhive', StateDirectory::resolve($home, 1000, null));
        self::assertSame('/var/lib/arkhive', StateDirectory::resolve($home, 0, 1000));
    }

    public function testTheOwnerOfTheSystemDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/arkhive-state-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            self::assertSame(fileowner($dir), StateDirectory::systemOwner($dir));
            self::assertNull(StateDirectory::systemOwner("{$dir}/missing"));
            touch("{$dir}/file");
            self::assertNull(StateDirectory::systemOwner("{$dir}/file"), 'not a directory');
        } finally {
            @unlink("{$dir}/file");
            rmdir($dir);
        }
    }

    public function testTheFirstAbsolutePath(): void
    {
        self::assertSame('/', StateDirectory::firstAbsolute('/'));
        self::assertSame('/b', StateDirectory::firstAbsolute('a:/b:/c'));
        self::assertNull(StateDirectory::firstAbsolute(''));
    }
}
