<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Repository;

use DateTimeImmutable;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Infrastructure\Restic\ResticJson;
use Mfonte\Arkhive\Infrastructure\Restic\ResticOutput;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRestic;

/**
 * Parsing of real restic 0.19 and 0.16 output (tests/Fixtures/restic).
 */
final class SnapshotModelsTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function snapshotsJson(string $fixture): array
    {
        $data = json_decode(FakeRestic::fixture($fixture), true);
        self::assertIsArray($data);

        return array_values($data);
    }

    public function testSnapshotFromResticJson(): void
    {
        $snapshot = ResticOutput::snapshot(self::snapshotsJson('snapshots-0.19.json')[1]);

        self::assertSame('6118b3c5b2b1ffc9e12d6022dfcab6275e9621122cb2b8d5b66d11a67fdc5c59', $snapshot->id);
        self::assertSame('6118b3c5', $snapshot->shortId());
        self::assertSame('web1.example.com', $snapshot->hostname);
        self::assertSame(['/srv/dumps', '/srv/www'], $snapshot->paths);
        self::assertSame(['arkhive'], $snapshot->tags);
        self::assertTrue($snapshot->hasTag('arkhive'));
        self::assertFalse($snapshot->hasTag('pre-restore'));
        self::assertSame(82, $snapshot->size);
        self::assertSame('root', $snapshot->username);
        self::assertSame('2026-10-12T14:14:37.913833+00:00', $snapshot->time->format('Y-m-d\TH:i:s.uP'));
    }

    public function testSnapshotsOfOldResticHaveNoSize(): void
    {
        $data = self::snapshotsJson('snapshots-0.16.json')[0];
        self::assertArrayNotHasKey('summary', $data);

        $snapshot = ResticOutput::snapshot($data);
        self::assertNull($snapshot->size);
        self::assertSame('4231c203', $snapshot->shortId());
    }

    public function testTimesWithNanosecondsAndOffsets(): void
    {
        self::assertSame('2026-10-12 16:14:37.204425 +02:00', ResticOutput::time('2026-10-12T16:14:37.204425279+02:00')->format('Y-m-d H:i:s.u P'));
        self::assertSame('2026-10-12 14:14:37', ResticOutput::time('2026-10-12T14:14:37Z')->format('Y-m-d H:i:s'));
        self::assertSame(0, ResticOutput::time('not a time')->getTimestamp());
    }

    public function testAge(): void
    {
        $snapshot = new Snapshot('ab', new DateTimeImmutable('2026-10-12T10:00:00Z'), 'h', ['/srv']);

        self::assertSame('30 min', $snapshot->age(new DateTimeImmutable('2026-10-12T10:30:00Z')));
        self::assertSame('5 h', $snapshot->age(new DateTimeImmutable('2026-10-12T15:10:00Z')));
        self::assertSame('47 h', $snapshot->age(new DateTimeImmutable('2026-10-14T09:00:00Z')));
        self::assertSame('3 d', $snapshot->age(new DateTimeImmutable('2026-10-15T10:00:00Z')));
        self::assertSame('2 w', $snapshot->age(new DateTimeImmutable('2026-10-27T10:00:00Z')));
        self::assertSame('0 min', $snapshot->age(new DateTimeImmutable('2026-10-12T09:00:00Z')), 'a clock behind the server is not a negative age');
        self::assertSame(5, $snapshot->ageHours(new DateTimeImmutable('2026-10-12T15:59:00Z')));
        self::assertSame(0, $snapshot->ageHours(new DateTimeImmutable('2026-10-12T09:00:00Z')));
    }

    public function testNodesFromLsOfBothVersions(): void
    {
        foreach (['ls-0.19.jsonl', 'ls-0.16.jsonl'] as $fixture) {
            $lines = explode("\n", trim(FakeRestic::fixture($fixture)));
            $first = ResticJson::decode($lines[0]);
            self::assertIsArray($first);
            self::assertSame('snapshot', $first['struct_type']);
            $node = ResticJson::decode($lines[2]);
            self::assertIsArray($node);
            $dir = ResticOutput::node($node);
            self::assertSame('/srv/www/empty', $dir->path, $fixture);
            self::assertTrue($dir->isDirectory());
            self::assertFalse($dir->isFile());
            self::assertSame('empty', $dir->name());
            self::assertSame('drwxr-xr-x', $dir->permissions);
        }

        $file = ResticOutput::node((array) json_decode(explode("\n", trim(FakeRestic::fixture('ls-dumps-0.19.jsonl')))[2], true));
        self::assertSame('/srv/dumps/mysql/shop.sql', $file->path);
        self::assertTrue($file->isFile());
        self::assertSame(25, $file->size);
        self::assertSame('-rw-r--r--', $file->permissions);
        self::assertSame(
            ['path' => '/srv/dumps/mysql/shop.sql', 'type' => 'file', 'size' => 25, 'permissions' => '-rw-r--r--', 'mtime' => '2026-10-12T14:14:34+00:00', 'uid' => 0, 'gid' => 0],
            $file->toArray(),
        );
    }

    public function testRepositoryLockAndStaleness(): void
    {
        $lock = ResticOutput::repositoryLock(str_repeat('c', 64), (array) json_decode(FakeRestic::fixture('lock-0.19.json'), true));
        self::assertFalse($lock->exclusive);
        self::assertSame(30066, $lock->pid);
        self::assertSame('a lock held by root@host.example.com (PID 30066) since 2026-10-12 14:14:49 Z', $lock->describe());

        $soon = new DateTimeImmutable('2026-10-12T14:20:00Z');
        $alive = static fn (int $pid): bool => true;
        $dead = static fn (int $pid): bool => false;
        self::assertFalse($lock->isStale($soon, 'host.example.com', $alive));
        self::assertTrue($lock->isStale($soon, 'host.example.com', $dead), 'this host and a dead PID');
        self::assertFalse($lock->isStale($soon, 'other.example.com', $dead), 'a PID on another host cannot be checked');
        self::assertTrue($lock->isStale(new DateTimeImmutable('2026-10-12T14:45:00Z'), 'other.example.com', $alive), 'older than 30 minutes');
        self::assertFalse($lock->isStale(new DateTimeImmutable('2026-10-12T14:44:49Z'), 'other.example.com', $alive), 'exactly 30 minutes is not yet stale');

        $exclusive = ResticOutput::repositoryLock('x', ['time' => '2026-10-12T14:14:49Z', 'exclusive' => true, 'hostname' => 'h', 'username' => 'u', 'pid' => 1]);
        self::assertTrue($exclusive->exclusive);
        self::assertStringStartsWith('an exclusive lock held by u@h (PID 1)', $exclusive->describe());
    }

    public function testRestoreProgressAndSummary(): void
    {
        [$status, $summary] = array_map(static fn (string $l): array => (array) ResticJson::decode($l), explode("\n", trim(FakeRestic::fixture('restore-0.19-progress.jsonl'))));

        self::assertSame(' 62.8%  3 files  89.78 MiB/143.05 MiB', ResticOutput::restoreProgress($status)->describe());
        $done = ResticOutput::restoreProgress($summary);
        self::assertSame(3, $done->filesRestored);
        self::assertSame(150000000, $done->bytesRestored);
        self::assertSame(1.0, $done->percentDone, 'a summary without percent_done is complete');
        self::assertSame('100.0%  3/3 files  143.05 MiB/143.05 MiB', $done->describe());
        self::assertSame(0.0, ResticOutput::restoreProgress(['message_type' => 'summary', 'total_bytes' => 10, 'bytes_restored' => 0])->percentDone);
    }
}
