<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Restore\Prompter;
use Mfonte\Arkhive\Services\Schedule\CronTable;
use Mfonte\Arkhive\Services\Schedule\Invocation;
use Mfonte\Arkhive\Services\Schedule\Schedule;
use Mfonte\Arkhive\Services\Schedule\SystemdUnits;
use Phar;

/**
 * spec §11: prints the systemd units or the cron lines that run backup, maintain and drill with the
 * current configuration. It never installs anything: the operator saves what it prints.
 */
final class ScheduleCommand extends ConfiguredCommand
{
    /** the ArkHive executable written into the schedule; null: worked out from how this one runs (tests set it) */
    /** @var list<string>|null */
    public static ?array $invocation = null;

    /** whether this host runs systemd; null: /run/systemd/system exists (tests set it) */
    public static ?bool $systemd = null;

    /** the account the jobs run as; null: the effective user (tests set it) */
    public static ?string $user = null;

    protected $signature = 'schedule
        {--systemd : Print systemd service and timer units}
        {--cron : Print cron lines}
        {--at=02:30 : Time of the daily backup (HH:MM); maintain runs on Sundays and drill on Wednesdays, three hours later}';

    protected $description = 'Print the systemd units or cron lines that run backup, maintain and drill with this configuration (installs nothing)';

    protected function perform(): int
    {
        [$hour, $minute] = Schedule::parseTime(self::stringOption($this->option('at')) ?? '');
        $format = $this->format();
        $config = $this->loadConfig();
        $source = realpath($config->source);
        $user = self::user();
        $schedule = new Schedule(
            self::invocation(),
            $source === false ? $config->source : $source,
            $user,
            $hour,
            $minute,
            $this->logFile($user),
        );

        if ($format === 'systemd') {
            $this->result($this->systemd($schedule));
        } else {
            $this->result(rtrim(CronTable::render($schedule)));
            if (!$schedule->root()) {
                $this->log->notice('Create the log directory first: mkdir -p ' . escapeshellarg(dirname($schedule->logFile)));
            }
        }
        if ($config->notify === null) {
            $this->log->warning('No notifications are configured: a scheduled run that fails would go unnoticed. Set NOTIFY=true, NOTIFY_ON=failure and the SMTP keys, or monitor with: arkhive status --max-age 26');
        }

        return ExitCode::SUCCESS;
    }

    private function systemd(Schedule $schedule): string
    {
        $text = '';
        foreach (SystemdUnits::render($schedule) as $path => $unit) {
            $text .= "# ==> {$path} <==\n{$unit}\n";
        }

        return $text
            . "# Save each unit above under its path, then enable the timers:\n"
            . "#   systemctl daemon-reload\n"
            . '#   systemctl enable --now ' . implode(' ', SystemdUnits::timers()) . "\n"
            . "# Check with: systemctl list-timers 'arkhive-*' and journalctl -u arkhive-backup.service";
    }

    /**
     * --systemd or --cron; asked in a terminal, a usage error when unattended.
     */
    private function format(): string
    {
        $systemd = (bool) $this->option('systemd');
        $cron = (bool) $this->option('cron');
        if ($systemd && $cron) {
            throw new UsageException('--systemd and --cron are alternatives: pass one of them');
        }
        if ($systemd || $cron) {
            return $systemd ? 'systemd' : 'cron';
        }
        if (!$this->context->interactive) {
            throw UsageException::missingFlag('--systemd or --cron', 'The scheduler');
        }
        $hasSystemd = self::$systemd ?? is_dir('/run/systemd/system');
        /** @var Prompter $prompter */
        $prompter = $this->laravel->make(Prompter::class);

        return $prompter->choose('Schedule with', ['systemd' => 'systemd timers', 'cron' => 'cron'], $hasSystemd ? 'systemd' : 'cron');
    }

    /**
     * Root logs to /var/log; anyone else to $XDG_STATE_HOME/arkhive (default ~/.local/state/arkhive).
     */
    private function logFile(string $user): string
    {
        if ($user === 'root') {
            return '/var/log/arkhive.log';
        }
        $environment = $this->terminal->environment();
        $state = $environment['XDG_STATE_HOME'] ?? '';
        if ($state === '' || !str_starts_with($state, '/')) {
            $state = ($environment['HOME'] ?? '') . '/.local/state';
        }

        return "{$state}/arkhive/arkhive.log";
    }

    /**
     * @return list<string>
     */
    private static function invocation(): array
    {
        if (self::$invocation !== null) {
            return self::$invocation;
        }

        return Invocation::resolve(Phar::running(false), base_path('arkhive'), PHP_BINARY, Invocation::standardFinder());
    }

    private static function user(): string
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $entry = posix_getpwuid(posix_geteuid());

        return is_array($entry) && $entry['name'] !== '' ? $entry['name'] : (string) posix_geteuid();
    }
}
