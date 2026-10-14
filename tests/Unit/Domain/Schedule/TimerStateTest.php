<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schedule;

use Mfonte\Arkhive\Domain\Schedule\Job;
use Mfonte\Arkhive\Domain\Schedule\TimerState;
use PHPUnit\Framework\TestCase;

final class TimerStateTest extends TestCase
{
    private const TIMER = ['LoadState' => 'loaded', 'UnitFileState' => 'enabled', 'ActiveState' => 'active', 'NextElapseUSecRealtime' => 'Thu 2026-10-15 02:30:00 UTC', 'LastTriggerUSec' => 'Wed 2026-10-14 02:31:12 UTC'];

    private const SUCCEEDED = ['Result' => 'success', 'ExecMainCode' => '1', 'ExecMainStatus' => '0', 'ExecMainStartTimestamp' => 'Wed 2026-10-14 02:31:12 UTC', 'ExecMainExitTimestamp' => 'Wed 2026-10-14 02:40:01 UTC'];

    public function testAnEnabledTimerWhoseLastRunSucceeded(): void
    {
        $state = TimerState::fromProperties(Job::Backup, self::TIMER, self::SUCCEEDED);

        self::assertTrue($state->healthy());
        self::assertSame('enabled', $state->describeTimer());
        self::assertSame('succeeded', $state->describeLast());
        self::assertSame('arkhive-backup.service', $state->service->unit);
        self::assertSame([
            'job' => 'backup',
            'installed' => true,
            'enabled' => 'enabled',
            'active' => 'active',
            'next' => 'Thu 2026-10-15 02:30:00 UTC',
            'last_trigger' => 'Wed 2026-10-14 02:31:12 UTC',
            'running' => false,
            'last_result' => 'success',
            'last_exit_status' => 0,
            'healthy' => true,
        ], $state->toArray());
    }

    public function testATimerThatNeverRan(): void
    {
        $state = TimerState::fromProperties(Job::Drill, ['LastTriggerUSec' => 'n/a'] + self::TIMER, ['Result' => 'success', 'ExecMainStartTimestamp' => '']);

        self::assertTrue($state->healthy(), 'not having run yet is not a failure');
        self::assertFalse($state->ran());
        self::assertSame('never ran', $state->describeLast());
        self::assertNull($state->toArray()['last_trigger']);
        self::assertNull($state->toArray()['last_result']);
        self::assertNull($state->toArray()['last_exit_status']);
    }

    public function testARunningJob(): void
    {
        $state = TimerState::fromProperties(Job::Maintain, self::TIMER, ['ExecMainExitTimestamp' => ''] + self::SUCCEEDED);

        self::assertTrue($state->running());
        self::assertTrue($state->healthy());
        self::assertSame('running', $state->describeLast());
        self::assertTrue($state->toArray()['running']);
        self::assertNull($state->toArray()['last_result']);
    }

    public function testAFailedLastRun(): void
    {
        $state = TimerState::fromProperties(Job::Backup, self::TIMER, ['Result' => 'exit-code', 'ExecMainStatus' => '69'] + self::SUCCEEDED);

        self::assertTrue($state->failed());
        self::assertFalse($state->healthy());
        self::assertSame('FAILED: exit 69 (UNAVAILABLE)', $state->describeLast());
        self::assertSame('exit-code', $state->toArray()['last_result']);
        self::assertSame(69, $state->toArray()['last_exit_status']);
    }

    public function testAMissingOrDisabledTimer(): void
    {
        $missing = TimerState::fromProperties(Job::Drill, ['LoadState' => 'not-found', 'UnitFileState' => '', 'ActiveState' => 'inactive', 'NextElapseUSecRealtime' => '', 'LastTriggerUSec' => ''], []);
        self::assertFalse($missing->installed);
        self::assertFalse($missing->healthy());
        self::assertSame('not installed', $missing->describeTimer());
        self::assertNull($missing->toArray()['next']);

        $disabled = TimerState::fromProperties(Job::Drill, ['UnitFileState' => 'disabled', 'ActiveState' => 'inactive'] + self::TIMER, self::SUCCEEDED);
        self::assertTrue($disabled->installed);
        self::assertFalse($disabled->healthy());
        self::assertSame('disabled, inactive', $disabled->describeTimer());

        $stopped = TimerState::fromProperties(Job::Drill, ['ActiveState' => 'inactive'] + self::TIMER, self::SUCCEEDED);
        self::assertFalse($stopped->healthy(), 'enabled but stopped: it would not fire');
        self::assertSame('enabled, inactive', $stopped->describeTimer());
    }
}
