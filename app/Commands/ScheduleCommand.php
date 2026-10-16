<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Output\TextTable;
use Mfonte\Arkhive\Application\Port\Prompter;
use Mfonte\Arkhive\Application\Port\ServiceUnits;
use Mfonte\Arkhive\Application\Schedule\Invocation;
use Mfonte\Arkhive\Application\Schedule\UnitInstaller;
use Mfonte\Arkhive\Domain\Schedule\CronTable;
use Mfonte\Arkhive\Domain\Schedule\Job;
use Mfonte\Arkhive\Domain\Schedule\Schedule;
use Mfonte\Arkhive\Domain\Schedule\SystemdUnits;
use Mfonte\Arkhive\Domain\Schedule\TimerState;
use Mfonte\Arkhive\Domain\Schedule\UnitState;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Phar;

/**
 * spec §11: prints the systemd units or the cron lines that run backup, maintain and drill with the
 * current configuration. --install writes the systemd units and enables the timers (root only);
 * --status shows how systemd sees them.
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

    /** the effective user id; null: posix_geteuid() (tests set it) */
    public static ?int $euid = null;

    /** where --install writes the units; null: SystemdUnits::DIRECTORY (tests set it) */
    public static ?string $unitDirectory = null;

    protected $signature = 'schedule
        {--systemd : Print systemd service and timer units}
        {--cron : Print cron lines}
        {--install : Write the systemd units under /etc/systemd/system and enable the timers (as root)}
        {--status : Show the timers as systemd sees them: enabled, next and last run, last result}
        {--json : With --status, print the timers as JSON on stdout}
        {--at=02:30 : Time of the daily backup (HH:MM); maintain runs on Sundays and drill on Wednesdays, three hours later}';

    protected $description = 'Print the systemd units or cron lines that run backup, maintain and drill with this configuration, or install and check the timers';

    protected function perform(): int
    {
        $install = (bool) $this->option('install');
        if ((bool) $this->option('status')) {
            if ($install || (bool) $this->option('cron') || (bool) $this->option('systemd')) {
                throw new UsageException('--status only reads the timers: it takes no --install, --systemd or --cron');
            }

            return $this->status();
        }
        [$hour, $minute] = Schedule::parseTime(self::stringOption($this->option('at')) ?? '');
        if ($install) {
            if ((bool) $this->option('cron')) {
                throw new UsageException('--install writes systemd units: it cannot be combined with --cron', hint: 'For cron, save the output of arkhive schedule --cron yourself');
            }
            if ((self::$euid ?? posix_geteuid()) !== 0) {
                throw new PermissionException('arkhive schedule --install writes to ' . self::unitDirectory() . ' and must run as root', hint: 'Run it as root (sudo), or save the output of arkhive schedule --systemd by hand');
            }
        }
        $format = $install ? 'systemd' : $this->format();
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
            // the units keep the system and the homes read-only: these stay writable
            array_values(array_unique(array_filter([$config->dumpDirectory, $config->restic->localPath()], static fn (?string $path): bool => $path !== null))),
        );

        if ($install) {
            $this->install($schedule);
        } elseif ($format === 'systemd') {
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

    /**
     * Writes the units, reloads systemd and enables the timers (idempotent: running it again after a
     * configuration change rewrites only what changed).
     */
    private function install(Schedule $schedule): void
    {
        $units = [];
        foreach (SystemdUnits::render($schedule) as $path => $unit) {
            $units[basename($path)] = $unit;
        }
        foreach (UnitInstaller::install($units, self::unitDirectory()) as $path => $written) {
            $this->log->info(($written ? 'Wrote ' : 'Unchanged: ') . $path);
        }
        /** @var ServiceUnits $systemd */
        $systemd = $this->laravel->make(ServiceUnits::class);
        $systemd->reload();
        $systemd->enableNow(SystemdUnits::timers());
        $this->log->success('Enabled ' . implode(', ', SystemdUnits::timers()) . ': check them with arkhive schedule --status');
    }

    /**
     * The timers and their last runs; exit 1 when one is missing, disabled or its last run failed.
     */
    private function status(): int
    {
        $timers = array_map(static fn (Job $job): string => "arkhive-{$job->value}.timer", Job::cases());
        $services = array_map(static fn (Job $job): string => "arkhive-{$job->value}.service", Job::cases());
        /** @var ServiceUnits $systemd */
        $systemd = $this->laravel->make(ServiceUnits::class);
        $timerProperties = $systemd->properties($timers, TimerState::PROPERTIES);
        $serviceProperties = $systemd->properties($services, UnitState::PROPERTIES);
        $states = [];
        foreach (Job::cases() as $i => $job) {
            $states[] = TimerState::fromProperties($job, $timerProperties[$timers[$i]] ?? [], $serviceProperties[$services[$i]] ?? []);
        }
        $healthy = array_filter($states, static fn (TimerState $state): bool => $state->healthy()) === $states;

        if ((bool) $this->option('json')) {
            $this->result(json_encode(['healthy' => $healthy, 'timers' => array_map(static fn (TimerState $state): array => $state->toArray(), $states)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->result(rtrim(TextTable::render(['JOB', 'TIMER', 'NEXT RUN', 'LAST RUN', 'LAST RESULT'], array_map(static fn (TimerState $state): array => [
                $state->job->value,
                $state->describeTimer(),
                $state->next === '' ? '-' : $state->next,
                $state->last === '' ? '-' : $state->last,
                $state->describeLast(),
            ], $states))));
        }
        foreach ($states as $state) {
            if (!$state->installed) {
                $this->log->warning("arkhive-{$state->job->value}.timer is not installed: run arkhive schedule --install");
            } elseif ($state->failed()) {
                $this->log->warning("The last {$state->job->value} failed: see journalctl -u arkhive-{$state->job->value}.service");
            } elseif (!$state->healthy()) {
                $this->log->warning("arkhive-{$state->job->value}.timer is {$state->describeTimer()}: enable it with systemctl enable --now arkhive-{$state->job->value}.timer");
            }
        }

        return $healthy ? ExitCode::SUCCESS : ExitCode::FAILURE;
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

    private static function unitDirectory(): string
    {
        return self::$unitDirectory ?? SystemdUnits::DIRECTORY;
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
