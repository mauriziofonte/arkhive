<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Schedule;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Schedule\Job;
use Mfonte\Arkhive\Services\Schedule\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function times(): iterable
    {
        yield 'default' => ['02:30', 2, 30];
        yield 'one digit hour' => ['3:05', 3, 5];
        yield 'midnight' => ['00:00', 0, 0];
        yield 'last minute' => ['23:59', 23, 59];
    }

    #[DataProvider('times')]
    public function testParseTime(string $time, int $hour, int $minute): void
    {
        self::assertSame([$hour, $minute], Schedule::parseTime($time));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTimes(): iterable
    {
        foreach (['', '24:00', '12:60', '12', '12:5', '1230', 'noon', '12:30:00', ' 12:30', '-1:30'] as $time) {
            yield json_encode($time) => [$time];
        }
    }

    #[DataProvider('badTimes')]
    public function testABadTimeIsAUsageError(string $time): void
    {
        try {
            Schedule::parseTime($time);
            self::fail("{$time} accepted");
        } catch (UsageException $e) {
            self::assertSame(ExitCode::USAGE, $e->exitCode());
            self::assertSame("--at must be a time of day as HH:MM (24 h), not '{$time}'", $e->getMessage());
            self::assertSame('For example: arkhive schedule --systemd --at 02:30', $e->hint());
        }
    }

    public function testJobsRunThreeHoursAfterTheBackupAcrossMidnight(): void
    {
        $schedule = new Schedule(['/usr/local/bin/arkhive'], '/etc/arkhive/config', 'root', 23, 15, '/var/log/arkhive.log');

        self::assertSame([23, 15], $schedule->timeOf(Job::Backup));
        self::assertSame([2, 15], $schedule->timeOf(Job::Maintain));
        self::assertSame([2, 15], $schedule->timeOf(Job::Drill));
        self::assertSame(['drill', '--config', '/etc/arkhive/config'], $schedule->arguments(Job::Drill));
        self::assertTrue($schedule->root());
        self::assertFalse((new Schedule(['/usr/local/bin/arkhive'], '/etc/arkhive/config', 'deploy', 2, 30, '/x.log'))->root());
    }

    /**
     * @return iterable<string, array{list<string>, string, string, string}>
     */
    public static function unwritable(): iterable
    {
        yield 'newline in the config path' => [['/usr/local/bin/arkhive'], "/etc/arkhive/con\nfig", 'root', '/var/log/arkhive.log'];
        yield 'tab in the executable' => [["/opt/ark\thive"], '/etc/arkhive/config', 'root', '/var/log/arkhive.log'];
        yield 'DEL in the log file' => [['/usr/local/bin/arkhive'], '/etc/arkhive/config', 'root', "/var/log/a\x7f.log"];
        yield 'empty user' => [['/usr/local/bin/arkhive'], '/etc/arkhive/config', '', '/var/log/arkhive.log'];
        yield 'empty executable' => [[''], '/etc/arkhive/config', 'root', '/var/log/arkhive.log'];
        yield 'newline in the PHAR after php' => [['/usr/bin/php', "/opt/arkhive\n.phar"], '/etc/arkhive/config', 'root', '/var/log/arkhive.log'];
    }

    /**
     * @param list<string> $invocation
     */
    #[DataProvider('unwritable')]
    public function testControlCharactersAndEmptyValuesAreRefused(array $invocation, string $config, string $user, string $log): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/^Cannot schedule ".*": a path or name with a control character \(or an empty one\) cannot be written into a unit or crontab$/');

        new Schedule($invocation, $config, $user, 2, 30, $log);
    }

    public function testJobFacts(): void
    {
        self::assertSame(['6h', '12h', '3h'], array_map(static fn (Job $job): string => $job->timeout(), Job::cases()));
        self::assertSame([null, 0, 3], array_map(static fn (Job $job): ?int => $job->weekday(), Job::cases()));
        self::assertSame([0, 3, 3], array_map(static fn (Job $job): int => $job->hourOffset(), Job::cases()));
        self::assertSame(['', 'Sun ', 'Wed '], array_map(static fn (Job $job): string => $job->calendarDay(), Job::cases()));
    }
}
