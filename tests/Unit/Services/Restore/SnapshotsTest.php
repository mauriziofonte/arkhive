<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Restore;

use DateTimeImmutable;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Restic\Snapshot;
use Mfonte\Arkhive\Services\Restore\Snapshots;
use PHPUnit\Framework\TestCase;

final class SnapshotsTest extends TestCase
{
    private static function snapshot(string $id, string $time, string $host = 'web1.example.com', array $tags = ['arkhive']): Snapshot
    {
        return new Snapshot($id, new DateTimeImmutable($time), $host, ['/srv/www'], $tags);
    }

    private static function list(): Snapshots
    {
        return new Snapshots([
            self::snapshot('ffff0000aaaa', '2026-10-12T12:00:00Z', 'web1.example.com', ['pre-restore']),
            self::snapshot('cccc1111bbbb', '2026-10-12T11:00:00Z', 'web2.example.com'),
            self::snapshot('cccc2222bbbb', '2026-10-12T10:00:00Z'),
            self::snapshot('aaaa3333bbbb', '2026-10-11T10:00:00Z'),
        ]);
    }

    public function testLatestIsTheNewestBackupOfTheHost(): void
    {
        self::assertSame('cccc2222bbbb', self::list()->resolve('latest', 'web1.example.com')->id, 'safety snapshots are never "latest"');
        self::assertSame('cccc1111bbbb', self::list()->resolve('LATEST', 'web2.example.com')->id);
    }

    public function testLatestWithSeveralHostsPrefersThisHostOrAsksForHost(): void
    {
        self::assertSame('cccc2222bbbb', self::list()->resolve('latest', null, 'web1.example.com')->id);

        try {
            self::list()->resolve('latest', null, 'new-server.example.com');
            self::fail('expected a usage error');
        } catch (UsageException|NoInputException $e) {
            self::assertSame("The repository holds snapshots of several hosts (web1.example.com, web2.example.com): 'latest' needs --host", $e->getMessage());
            self::assertSame(ExitCode::USAGE, $e->exitCode());
        }
    }

    public function testLatestWithOneHostNeedsNoHost(): void
    {
        $one = new Snapshots([self::snapshot('bbbb', '2026-10-12T10:00:00Z', 'old.example.com')]);

        self::assertSame('bbbb', $one->resolve('latest', null, 'new.example.com')->id, 'bare metal: the new host has another name');
    }

    public function testIdPrefixes(): void
    {
        self::assertSame('aaaa3333bbbb', self::list()->resolve('aaaa')->id);
        self::assertSame('ffff0000aaaa', self::list()->resolve('FFFF0000')->id, 'a safety snapshot by id');
        self::assertSame('cccc1111bbbb', self::list()->resolve('cccc', 'web2.example.com')->id, 'the host narrows the prefix');
    }

    public function testAmbiguousUnknownAndInvalidIds(): void
    {
        foreach ([
            'cccc' => 'Snapshot id cccc is ambiguous: it matches cccc1111, cccc2222',
            'dddd' => 'There is no snapshot dddd in the repository',
            'abc' => "--snapshot must be a snapshot id or 'latest', not 'abc'",
            '../etc' => "--snapshot must be a snapshot id or 'latest', not '../etc'",
        ] as $reference => $message) {
            try {
                self::list()->resolve($reference);
                self::fail("{$reference} should be refused");
            } catch (UsageException|NoInputException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testNoSnapshotAtAll(): void
    {
        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('The repository has no snapshot yet');
        (new Snapshots([]))->resolve('latest');
    }

    public function testOnlySafetySnapshotsOrAnUnknownHost(): void
    {
        $safety = new Snapshots([self::snapshot('aaaa', '2026-10-12T10:00:00Z', 'h', ['pre-restore'])]);
        try {
            $safety->resolve('latest');
            self::fail('expected a usage error');
        } catch (UsageException|NoInputException $e) {
            self::assertStringContainsString('only safety snapshots', $e->getMessage());
        }

        $this->expectExceptionMessage('There is no snapshot of host nobody.example.com in the repository');
        self::list()->resolve('latest', 'nobody.example.com');
    }

    public function testHostsAndFilters(): void
    {
        self::assertSame(['web1.example.com', 'web2.example.com'], self::list()->hosts());
        self::assertCount(3, self::list()->forHost('web1.example.com')->snapshots);
        self::assertCount(4, self::list()->forHost(null)->snapshots);
        self::assertCount(3, self::list()->backups()->snapshots);
        self::assertFalse(self::list()->isEmpty());
        self::assertTrue((new Snapshots([]))->isEmpty());
    }
}
