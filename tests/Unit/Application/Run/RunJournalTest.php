<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Run;

use DateTimeImmutable;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Run\RunJournal;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Repository\RepositoryKey;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemoryRunStateStore;

final class RunJournalTest extends TestCase
{
    private LogStream $stream;
    private Logger $log;

    protected function setUp(): void
    {
        $this->stream = LogStream::memory();
        $this->log = new Logger(new ExecutionContext(false, false, false, LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock(), true);
    }

    private static function record(string $job, int $exit): RunRecord
    {
        return new RunRecord($job, new DateTimeImmutable('2026-10-12T03:00:00+00:00'), new DateTimeImmutable('2026-10-12T03:01:00+00:00'), $exit);
    }

    public function testRecordsUpdateTheStateOfTheirJob(): void
    {
        $store = new InMemoryRunStateStore();
        $journal = new RunJournal($store);

        $journal->record(self::record('backup', 0), $this->log);
        $journal->record(self::record('backup', 75), $this->log);
        $journal->record(self::record('drill', 65), $this->log);

        $states = $journal->states();
        self::assertSame(['backup', 'maintain', 'drill'], array_keys($states));
        self::assertSame(75, $states['backup']->last?->exitCode);
        self::assertSame(0, $states['backup']->lastSuccess?->exitCode);
        self::assertNull($states['maintain']->last);
        self::assertSame(65, $states['drill']->last?->exitCode);
        self::assertNull($states['drill']->lastSuccess);
        self::assertSame('/var/lib/arkhive', $journal->location());
        self::assertSame('', $this->stream->contents());
    }

    public function testAStoreThatCannotBeWrittenIsOnlyAWarning(): void
    {
        $store = new InMemoryRunStateStore();
        $store->failing = true;

        (new RunJournal($store))->record(self::record('maintain', 0), $this->log);

        self::assertStringContainsString('warning Could not record the outcome of this maintain in /var/lib/arkhive: Cannot write /var/lib/arkhive/maintain.json', $this->stream->contents());
    }

    public function testWithoutAStateDirectoryNothingIsRecorded(): void
    {
        $store = new InMemoryRunStateStore(null);

        (new RunJournal($store))->record(self::record('backup', 0), $this->log);

        self::assertSame([], $store->states);
        self::assertStringContainsString('debug No state directory: the outcome of this run is not recorded', $this->stream->contents());
    }

    public function testARunOnARepositoryIsRecordedForTheHostAndForThatRepository(): void
    {
        $store = new InMemoryRunStateStore();
        $journal = new RunJournal($store);
        $a = RepositoryKey::of('/srv/restic-a');
        $b = RepositoryKey::of('/srv/restic-b');
        $at = new DateTimeImmutable('2026-10-12T03:00:00+00:00');

        $journal->record(new RunRecord('backup', $at, $at->modify('+60 seconds'), 0, 'aaaa1111', repository: $a, host: 'web1.example.com'), $this->log);
        $journal->record(new RunRecord('backup', $at->modify('+1 hour'), $at->modify('+61 minutes'), 0, 'bbbb2222', repository: $b, host: 'web1.example.com'), $this->log);
        $journal->record(self::record('backup', 78), $this->log);

        self::assertSame(78, $journal->states()['backup']->last?->exitCode, 'the host state has every run, the repository unknown too');
        self::assertSame('bbbb2222', $journal->states()['backup']->lastSuccess?->snapshotId);
        self::assertSame('aaaa1111', $journal->states($a)['backup']->lastSuccess?->snapshotId);
        self::assertSame('aaaa1111', $journal->states($a)['backup']->last?->snapshotId, 'runs on b are not runs on a');
        self::assertSame('bbbb2222', $journal->states($b)['backup']->last?->snapshotId);
        self::assertSame('', $this->stream->contents());
    }
}
