<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Run;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunRecordTest extends TestCase
{
    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private static function record(int $exit, ?string $snapshot = null, ?string $error = null, string $started = '2026-10-12 03:00:00'): RunRecord
    {
        return new RunRecord('backup', self::at($started), self::at($started)->modify('+90 seconds'), $exit, $snapshot, $exit === 3 ? ['1 file unreadable'] : [], $error);
    }

    /**
     * @return iterable<string, array{int, bool, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'ok' => [0, true, 'ok'];
        yield 'warnings' => [3, true, 'warning'];
        yield 'failure' => [1, false, 'failed'];
        yield 'locked' => [75, false, 'failed'];
        yield 'interrupted' => [143, false, 'failed'];
    }

    #[DataProvider('outcomes')]
    public function testTheExitCodeDecidesTheOutcome(int $exit, bool $succeeded, string $status): void
    {
        self::assertSame($succeeded, self::record($exit)->succeeded());
        self::assertSame($status, self::record($exit)->status());
    }

    public function testDescribeAndDuration(): void
    {
        $paris = new DateTimeZone('Europe/Paris');

        self::assertSame(90.0, self::record(0)->duration());
        self::assertSame('2026-10-12 05:00 CEST, OK, snapshot e82340a7', self::record(0, 'e82340a77eda4178')->describe($paris));
        self::assertSame('2026-10-12 03:00 UTC, completed with warnings', self::record(3)->describe(new DateTimeZone('UTC')));
        self::assertSame('2026-10-12 03:00 UTC, FAILED with exit 69: There is no restic repository', self::record(69, error: 'There is no restic repository')->describe(new DateTimeZone('UTC')));
        self::assertSame('2026-10-12 03:00 UTC, FAILED with exit 130', self::record(130)->describe(new DateTimeZone('UTC')));
        self::assertSame(0.0, (new RunRecord('drill', self::at('2026-10-12 03:00:00'), self::at('2026-10-12 02:59:00'), 0))->duration(), 'a clock step back is not a negative duration');
    }

    public function testItSurvivesItsStoredForm(): void
    {
        $run = self::record(3, 'e82340a77eda4178', 'partial');

        self::assertSame([
            'job' => 'backup',
            'started' => '2026-10-12T03:00:00+00:00',
            'ended' => '2026-10-12T03:01:30+00:00',
            'duration_seconds' => 90.0,
            'exit_code' => 3,
            'status' => 'warning',
            'snapshot_id' => 'e82340a77eda4178',
            'warnings' => ['1 file unreadable'],
            'error' => 'partial',
            'invocation' => null,
            'notified' => false,
            'repository' => null,
            'host' => null,
        ], $run->toArray());
        self::assertEquals($run, RunRecord::fromArray($run->toArray()));
        self::assertEquals(self::record(0), RunRecord::fromArray(self::record(0)->toArray()));

        $unit = new RunRecord('backup', $run->started, $run->ended, 69, invocation: '6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', notified: true);
        self::assertSame(['6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', true], [$unit->toArray()['invocation'], $unit->toArray()['notified']]);
        self::assertEquals($unit, RunRecord::fromArray($unit->toArray()));
        $stored = $unit->toArray();
        $stored['invocation'] = 42;
        $stored['notified'] = 'yes';
        $read = RunRecord::fromArray($stored);
        self::assertNotNull($read);
        self::assertSame([null, false], [$read->invocation, $read->notified], 'wrong types read as unknown');
        unset($stored['invocation'], $stored['notified']);
        self::assertNotNull(RunRecord::fromArray($stored), 'a 2.1.0-rc record without them still reads');

        $keyed = new RunRecord('backup', $run->started, $run->ended, 0, 'e82340a77eda4178', repository: '0123456789abcdef', host: 'web1.example.com');
        self::assertSame(['0123456789abcdef', 'web1.example.com'], [$keyed->toArray()['repository'], $keyed->toArray()['host']]);
        self::assertEquals($keyed, RunRecord::fromArray($keyed->toArray()));
        $stored = $keyed->toArray();
        $stored['repository'] = '/srv/restic-repo';
        $stored['host'] = '';
        $read = RunRecord::fromArray($stored);
        self::assertNotNull($read);
        self::assertSame([null, null], [$read->repository, $read->host], 'only a RepositoryKey and a host name');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function damaged(): iterable
    {
        $good = self::record(0)->toArray();
        yield 'not an array' => ['backup'];
        yield 'no job' => [array_diff_key($good, ['job' => 1])];
        yield 'exit code as text' => [['exit_code' => '0'] + $good];
        yield 'no start' => [array_diff_key($good, ['started' => 1])];
        yield 'bad start' => [['started' => 'yesterday'] + $good];
        yield 'bad end' => [['ended' => '2026-10-12'] + $good];
        yield 'no end' => [array_diff_key($good, ['ended' => 1])];
    }

    #[DataProvider('damaged')]
    public function testADamagedRecordIsIgnored(mixed $data): void
    {
        self::assertNull(RunRecord::fromArray($data));
    }

    public function testOptionalFieldsOfTheWrongTypeAreDropped(): void
    {
        $run = RunRecord::fromArray(['snapshot_id' => 12, 'warnings' => ['a', 3, 'b'], 'error' => false] + self::record(0)->toArray());

        self::assertNotNull($run);
        self::assertNull($run->snapshotId);
        self::assertSame(['a', 'b'], $run->warnings);
        self::assertNull($run->error);
        self::assertSame([], RunRecord::fromArray(['warnings' => 'x'] + self::record(0)->toArray())?->warnings);
    }

    public function testTheJobStateKeepsTheLastRunAndTheLastSuccess(): void
    {
        $first = self::record(0, 'aaaa', started: '2026-10-10 03:00:00');
        $second = self::record(69, started: '2026-10-11 03:00:00');
        $third = self::record(3, 'bbbb', started: '2026-10-12 03:00:00');

        $state = (new JobState())->with($first)->with($second);
        self::assertSame($second, $state->last);
        self::assertSame($first, $state->lastSuccess);
        $state = $state->with($third);
        self::assertSame($third, $state->last);
        self::assertSame($third, $state->lastSuccess);

        self::assertEquals($state, JobState::fromArray($state->toArray()));
        self::assertEquals(new JobState(), JobState::fromArray('nonsense'));
        self::assertEquals(new JobState(), JobState::fromArray([]));
        self::assertSame(['last' => null, 'last_success' => null], (new JobState())->toArray());
        self::assertSame(['backup', 'maintain', 'drill'], RunRecord::JOBS);
    }
}
