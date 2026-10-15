<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Run;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Domain\Run\RepositoryHealth;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use PHPUnit\Framework\TestCase;

final class RepositoryHealthTest extends TestCase
{
    private static function record(string $job, int $exit, string $started): RunRecord
    {
        $at = new DateTimeImmutable($started, new DateTimeZone('UTC'));

        return new RunRecord($job, $at, $at->modify('+10 minutes'), $exit);
    }

    /**
     * @param list<RunRecord> $runs in the order they ran
     *
     * @return array<string, JobState>
     */
    private static function states(array $runs): array
    {
        $states = ['backup' => new JobState(), 'maintain' => new JobState(), 'drill' => new JobState()];
        foreach ($runs as $run) {
            $states[$run->job] = $states[$run->job]->with($run);
        }

        return $states;
    }

    public function testNothingRecordedOrHealthyRunsAreNoDamage(): void
    {
        self::assertNull(RepositoryHealth::unresolvedDataError([]));
        self::assertNull(RepositoryHealth::unresolvedDataError(self::states([self::record('maintain', 0, '2026-10-11 04:00'), self::record('drill', 0, '2026-10-12 05:00')])));
        self::assertNull(RepositoryHealth::unresolvedDataError(self::states([self::record('maintain', 75, '2026-10-11 04:00')])), 'a lock is not damage');
    }

    public function testAFailedCheckOrDrillStaysUntilAMaintainPasses(): void
    {
        $failed = self::record('maintain', 65, '2026-10-11 04:00');
        self::assertSame($failed, RepositoryHealth::unresolvedDataError(self::states([$failed])));

        $drill = self::record('drill', 65, '2026-10-12 05:00');
        self::assertSame($drill, RepositoryHealth::unresolvedDataError(self::states([self::record('maintain', 0, '2026-10-11 04:00'), $drill])), 'the passing maintain came before the failed drill');
        self::assertSame($drill, RepositoryHealth::unresolvedDataError(self::states([$failed, $drill])), 'the latest damage is named');

        self::assertNull(RepositoryHealth::unresolvedDataError(self::states([$failed, $drill, self::record('maintain', 0, '2026-10-13 04:00')])), 'repaired and checked');
    }

    public function testThePassingDrillDoesNotClearAFailedCheck(): void
    {
        $failed = self::record('maintain', 65, '2026-10-11 04:00');

        self::assertSame($failed, RepositoryHealth::unresolvedDataError(self::states([$failed, self::record('drill', 0, '2026-10-12 05:00')])));
    }

    public function testTheWarningNamesTheRunThatFoundTheDamage(): void
    {
        self::assertSame(
            'the last maintain (2026-10-11 06:10 CEST) found the repository damaged and no maintain has passed since: this snapshot may use data the repository no longer has; repair it (arkhive maintain names the steps)',
            RepositoryHealth::warning(self::record('maintain', 65, '2026-10-11 04:00'), new DateTimeZone('Europe/Rome')),
        );
    }
}
