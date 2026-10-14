<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Schedule;

/**
 * One scheduled ArkHive command: when it runs and how long it may take.
 */
enum Job: string
{
    case Backup = 'backup';
    case Maintain = 'maintain';
    case Drill = 'drill';

    public function description(): string
    {
        return match ($this) {
            self::Backup => 'ArkHive backup',
            self::Maintain => 'ArkHive maintenance (retention, prune, check)',
            self::Drill => 'ArkHive restore drill',
        };
    }

    /**
     * Daily backup; maintain and drill weekly, three hours after the backup, on different days so
     * they do not meet.
     */
    public function hourOffset(): int
    {
        return $this === self::Backup ? 0 : 3;
    }

    /**
     * Day of the week (cron numbering, 0 = Sunday), or null for every day.
     */
    public function weekday(): ?int
    {
        return match ($this) {
            self::Backup => null,
            self::Maintain => 0,
            self::Drill => 3,
        };
    }

    /**
     * The day-of-week prefix of a systemd OnCalendar= expression (matches weekday()).
     */
    public function calendarDay(): string
    {
        return match ($this) {
            self::Backup => '',
            self::Maintain => 'Sun ',
            self::Drill => 'Wed ',
        };
    }

    /**
     * systemd TimeoutStartSec: a run past this is stopped with SIGTERM (ArkHive cleans up, exit 143).
     */
    public function timeout(): string
    {
        return match ($this) {
            self::Backup => '6h',
            self::Maintain => '12h',
            self::Drill => '3h',
        };
    }
}
