<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Repository;

use Mfonte\Arkhive\Domain\Repository\LockConflict;
use Mfonte\Arkhive\Infrastructure\Restic\ResticErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockConflictTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function capturedStderr(): iterable
    {
        foreach (['locked-0.19.stderr', 'locked-json-0.18.stderr', 'locked-json-0.19.stderr'] as $fixture) {
            yield $fixture => [(string) file_get_contents(__DIR__ . '/../../../Fixtures/restic/' . $fixture)];
        }
    }

    #[DataProvider('capturedStderr')]
    public function testTheLockResticNamesIsRead(string $stderr): void
    {
        $conflict = LockConflict::parse(ResticErrors::text($stderr));

        self::assertEquals(new LockConflict(25983, 'host.example.com', 'root', false), $conflict);
        self::assertTrue($conflict?->madeOn('host.example.com'));
        self::assertFalse($conflict?->madeOn('web2.example.com'));
    }

    public function testAnExclusiveLockOfRestic011(): void
    {
        $conflict = LockConflict::parse("Fatal: unable to create lock in backend: repository is already locked exclusively by PID 812 on web1 by backup user (UID 1001, GID 1001)\nlock was created at 2026-10-12 03:00:01 (12m4s ago)\nstorage ID 0a1b2c3d\n");

        self::assertEquals(new LockConflict(812, 'web1', 'backup user', true), $conflict);
    }

    public function testNoLockNamedIsNull(): void
    {
        self::assertNull(LockConflict::parse('Fatal: wrong password or no key found'));
        self::assertNull(LockConflict::parse(''));
    }

    public function testPidZeroCannotBeLookedUp(): void
    {
        self::assertFalse((new LockConflict(0, 'web1', 'root', false))->madeOn('web1'));
    }
}
