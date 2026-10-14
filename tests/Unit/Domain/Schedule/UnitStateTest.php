<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schedule;

use Mfonte\Arkhive\Domain\Schedule\Job;
use Mfonte\Arkhive\Domain\Schedule\UnitState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitStateTest extends TestCase
{
    public function testFromSystemctlShow(): void
    {
        $state = UnitState::fromProperties('arkhive-backup.service', [
            'Id' => 'arkhive-backup.service',
            'Result' => 'exit-code',
            'ExecMainCode' => '1',
            'ExecMainStatus' => '69',
            'InvocationID' => '6B1F0B6BD2B4405E8BF5E0C4D0C5E1AA',
            'ExecMainStartTimestamp' => 'Wed 2026-10-14 02:31:12 UTC',
            'ExecMainExitTimestamp' => 'Wed 2026-10-14 02:31:40 UTC',
        ]);

        self::assertSame(['arkhive-backup.service', 'exit-code', 1, 69, '6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', 'Wed 2026-10-14 02:31:12 UTC', 'Wed 2026-10-14 02:31:40 UTC'], [$state->unit, $state->result, $state->mainCode, $state->mainStatus, $state->invocationId, $state->started, $state->ended]);
        self::assertSame(Job::Backup, $state->job());
        self::assertSame('exit 69 (UNAVAILABLE)', $state->describe());

        $bare = UnitState::fromProperties('other.service', ['InvocationID' => '']);
        self::assertSame(['other.service', '', 0, 0, null, '', ''], [$bare->unit, $bare->result, $bare->mainCode, $bare->mainStatus, $bare->invocationId, $bare->started, $bare->ended]);
        self::assertNull($bare->job());
        self::assertSame('not started', $bare->describe());
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'sysexits code' => ['exit-code', 1, 75, 'exit 75 (TEMPFAIL)'];
        yield 'other exit code' => ['exit-code', 1, 2, 'exit 2'];
        yield 'timeout' => ['timeout', 2, 15, 'killed by signal 15 (TERM), result timeout'];
        yield 'oom' => ['oom-kill', 2, 9, 'killed by signal 9 (KILL), result oom-kill'];
        yield 'unknown signal' => ['signal', 2, 10, 'killed by signal 10'];
        yield 'core dump' => ['core-dump', 3, 11, 'core dumped, killed by signal 11 (SEGV)'];
        yield 'watchdog' => ['watchdog', 2, 6, 'killed by signal 6 (ABRT), result watchdog'];
        yield 'start limit' => ['start-limit-hit', 0, 0, 'not started, result start-limit-hit'];
    }

    #[DataProvider('outcomes')]
    public function testDescribe(string $result, int $code, int $status, string $expected): void
    {
        self::assertSame($expected, (new UnitState('arkhive-backup.service', $result, $code, $status, null))->describe());
    }

    public function testOnlyArkhiveJobUnitsHaveAJob(): void
    {
        foreach (['arkhive-maintain.service' => Job::Maintain, 'arkhive-drill.service' => Job::Drill, 'arkhive-restore.service' => null, 'arkhive-backup.timer' => null, 'xarkhive-backup.service' => null] as $unit => $job) {
            self::assertSame($job, (new UnitState($unit, '', 0, 0, null))->job(), $unit);
        }
    }
}
