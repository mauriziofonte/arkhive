<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Run;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\Domain\Repository\RepositoryKey;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\Domain\Run\SnapshotContinuity;
use PHPUnit\Framework\TestCase;

final class SnapshotContinuityTest extends TestCase
{
    private const NEWEST = 'e82340a77eda41788f329309f282cd62f1d4cad85933d8b22b6e4e72a0ab05b2';
    private const OLDER = '6118b3c5b2b1ffc9e12d6022dfcab6275e9621122cb2b8d5b66d11a67fdc5c59';
    private const GONE = '9a3f1c0e5b7d4e2f8a6c1b3d5e7f9a0b2c4d6e8f0a1b3c5d7e9f1a2b3c4d5e6f';

    private static function key(): string
    {
        return RepositoryKey::of('sftp:backup@backup.example.com:/restic');
    }

    private static function snapshot(string $id, string $time, string $host = 'web1.example.com', string $tag = 'arkhive'): Snapshot
    {
        return new Snapshot($id, new DateTimeImmutable($time), $host, ['/srv/www'], [$tag]);
    }

    private static function repository(Snapshot ...$snapshots): Snapshots
    {
        return new Snapshots(array_values($snapshots));
    }

    private static function backup(string $id, string $started = '2026-10-12T14:17:50+00:00', ?string $repository = null, ?string $host = 'web1.example.com'): RunRecord
    {
        $start = new DateTimeImmutable($started);

        return new RunRecord('backup', $start, $start->modify('+65 seconds'), 0, $id, repository: $repository ?? self::key(), host: $host);
    }

    public function testTheRecordedSnapshotIsThere(): void
    {
        $repository = self::repository(self::snapshot(self::NEWEST, '2026-10-12T14:17:53Z'), self::snapshot(self::OLDER, '2026-10-12T14:14:37Z'));

        $continuity = SnapshotContinuity::check(self::backup(self::NEWEST), self::key(), $repository);

        self::assertSame(SnapshotContinuity::OK, $continuity->status);
        self::assertFalse($continuity->broken());
        self::assertNull($continuity->message(new DateTimeZone('UTC')));
        self::assertSame(SnapshotContinuity::OK, SnapshotContinuity::check(self::backup('e82340a77eda4178'), self::key(), $repository)->status, 'a short id recorded by an older restic');
        self::assertSame(SnapshotContinuity::OK, SnapshotContinuity::check(self::backup(self::OLDER, '2026-10-12T14:14:30+00:00'), self::key(), $repository)->status);
    }

    public function testADeletedSnapshotWithOnlyOlderOnesLeftIsARollback(): void
    {
        $repository = self::repository(self::snapshot(self::OLDER, '2026-10-12T14:14:37Z'), self::snapshot(self::NEWEST, '2026-10-12T14:20:00Z', 'web2.example.com'));

        $continuity = SnapshotContinuity::check(self::backup(self::GONE), self::key(), $repository);

        self::assertSame(SnapshotContinuity::MISSING, $continuity->status);
        self::assertTrue($continuity->broken());
        self::assertSame(
            'snapshot ' . self::GONE . ' recorded as the last successful backup at 2026-10-12 14:18 UTC is missing from the repository (deleted or rolled back);'
            . ' the newest snapshot of web1.example.com in it, 6118b3c5 of 2026-10-12 14:14 UTC, is older',
            $continuity->message(new DateTimeZone('UTC')),
        );
        self::assertSame([
            'status' => 'missing',
            'recorded_snapshot' => self::GONE,
            'recorded_at' => '2026-10-12T14:18:55+00:00',
            'newest_snapshot' => self::OLDER,
            'newest_time' => '2026-10-12T14:14:37+00:00',
            'newest_older' => true,
        ], $continuity->toArray());
    }

    public function testNoSnapshotOfTheHostLeft(): void
    {
        $repository = self::repository(self::snapshot(self::NEWEST, '2026-10-13T03:00:00Z', 'web1.example.com', 'pre-restore'));

        $continuity = SnapshotContinuity::check(self::backup(self::GONE), self::key(), $repository);

        self::assertSame(SnapshotContinuity::MISSING, $continuity->status, 'a safety snapshot is not a backup');
        self::assertStringEndsWith('(deleted or rolled back); there is no snapshot of web1.example.com left in it', (string) $continuity->message(new DateTimeZone('UTC')));
        self::assertFalse($continuity->toArray()['newest_older']);
        self::assertSame(SnapshotContinuity::MISSING, SnapshotContinuity::check(self::backup(self::GONE), self::key(), self::repository())->status, 'an emptied repository');
    }

    public function testANewerBackupOfTheHostSupersedesTheRecordedOne(): void
    {
        $repository = self::repository(self::snapshot(self::NEWEST, '2026-10-13T03:00:00Z'));

        $continuity = SnapshotContinuity::check(self::backup(self::GONE), self::key(), $repository);

        self::assertSame(SnapshotContinuity::SUPERSEDED, $continuity->status, 'retention may remove it once a newer backup exists');
        self::assertFalse($continuity->broken());
        self::assertNull($continuity->message(new DateTimeZone('UTC')));
        self::assertSame(self::NEWEST, $continuity->toArray()['newest_snapshot']);
    }

    public function testRecordsKeepWholeSecondsSoOnlyASnapshotTakenAfterTheRunEndedIsNewer(): void
    {
        // the run started at 14:17:50.9 and ended at 14:18:55.2: its record says 14:17:50 and 14:18:55
        $before = self::repository(self::snapshot(self::OLDER, '2026-10-12T14:17:50.400Z'));
        $missing = SnapshotContinuity::check(self::backup(self::GONE), self::key(), $before);
        self::assertSame(SnapshotContinuity::MISSING, $missing->status, 'the backup before it, taken in the second the run started in');
        self::assertTrue($missing->toArray()['newest_older']);

        $during = self::repository(self::snapshot(self::OLDER, '2026-10-12T14:18:54.999Z'));
        self::assertSame(SnapshotContinuity::MISSING, SnapshotContinuity::check(self::backup(self::GONE), self::key(), $during)->status);

        $after = self::repository(self::snapshot(self::NEWEST, '2026-10-12T14:18:55.300Z'));
        $superseded = SnapshotContinuity::check(self::backup(self::GONE), self::key(), $after);
        self::assertSame(SnapshotContinuity::SUPERSEDED, $superseded->status, 'the next run, started in the second this one ended in');
        self::assertFalse($superseded->toArray()['newest_older']);
        self::assertSame(SnapshotContinuity::SUPERSEDED, SnapshotContinuity::check(self::backup(self::GONE), self::key(), self::repository(self::snapshot(self::NEWEST, '2026-10-12T14:18:55Z')))->status);
    }

    public function testRecordsThatCannotBeCheckedAreNotRecorded(): void
    {
        $empty = self::repository();

        self::assertSame(SnapshotContinuity::NOT_RECORDED, SnapshotContinuity::check(null, self::key(), $empty)->status);
        $failed = new RunRecord('backup', new DateTimeImmutable('2026-10-12T14:17:50Z'), new DateTimeImmutable('2026-10-12T14:18:00Z'), 0, null, repository: self::key(), host: 'web1.example.com');
        self::assertSame(SnapshotContinuity::NOT_RECORDED, SnapshotContinuity::check($failed, self::key(), $empty)->status, 'no snapshot id');
        $legacy = new RunRecord('backup', new DateTimeImmutable('2026-10-12T14:17:50Z'), new DateTimeImmutable('2026-10-12T14:18:00Z'), 0, self::GONE);
        self::assertSame(SnapshotContinuity::NOT_RECORDED, SnapshotContinuity::check($legacy, self::key(), $empty)->status, '2.1.0-rc.1: may be of another repository');
        self::assertSame(SnapshotContinuity::NOT_RECORDED, SnapshotContinuity::check(self::backup(self::GONE, repository: RepositoryKey::of('/srv/other')), self::key(), $empty)->status);
        self::assertSame(SnapshotContinuity::NOT_RECORDED, SnapshotContinuity::check(self::backup(self::GONE, host: null), self::key(), $empty)->status);
        self::assertSame(['status' => 'not_recorded', 'recorded_snapshot' => null, 'recorded_at' => null, 'newest_snapshot' => null, 'newest_time' => null, 'newest_older' => false], SnapshotContinuity::check(null, self::key(), $empty)->toArray());
    }

    public function testTheRepositoryKeyIsTheLockHash(): void
    {
        self::assertSame(substr(hash('sha256', '/srv/restic-repo'), 0, 16), RepositoryKey::of('/srv/restic-repo'));
        self::assertTrue(RepositoryKey::valid(RepositoryKey::of('/srv/restic-repo')));
        self::assertFalse(RepositoryKey::valid('../../etc'));
        self::assertFalse(RepositoryKey::valid('0123456789ABCDEF'));
    }
}
