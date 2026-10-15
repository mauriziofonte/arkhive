<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Port\ServiceUnits;
use Mfonte\Arkhive\Application\Run\RunJournal;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Domain\Schedule\UnitState;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;

/**
 * Run by arkhive-failure@.service (OnFailure= of the scheduled units): sends the configured
 * notification about a unit that failed, with its last journal lines. A run that already sent its
 * own notification is not reported twice; this covers the runs that could not: killed by the
 * timeout or the OOM killer, or failed before the configuration loaded.
 */
final class NotifyCommand extends ConfiguredCommand
{
    /** journal lines of the failed run in the e-mail */
    public const JOURNAL_LINES = 30;

    protected $signature = 'notify
        {--failed-unit= : The systemd service that failed, e.g. arkhive-backup.service}';

    protected $description = 'Send the configured notification about a failed systemd unit (run by arkhive-failure@.service)';

    protected function perform(): int
    {
        $unit = self::stringOption($this->option('failed-unit'));
        if ($unit === null) {
            throw new UsageException('notify needs --failed-unit', 'It is run by arkhive-failure@.service as: arkhive notify --failed-unit %i');
        }
        if (preg_match('/^[A-Za-z0-9:_.@\\\\-]+\.service$/', $unit) !== 1) {
            throw new UsageException("--failed-unit must name a service, such as arkhive-backup.service, not '{$unit}'");
        }
        $units = $this->laravel->make(ServiceUnits::class);
        $state = $units->state($unit);
        if ($this->alreadyNotified($state)) {
            $this->log->info("{$unit} sent its own notification about this run ({$state->describe()}): nothing more to send");

            return ExitCode::SUCCESS;
        }

        $config = $this->loadConfig();
        if ($config->notify === null) {
            $this->log->warning("{$unit} failed ({$state->describe()}), but no notifications are configured: nothing sent");
            $this->log->notice('Set NOTIFY=true and the SMTP keys in ' . $config->source . ' to be told about failed runs');

            return ExitCode::WARNING;
        }
        $lines = $state->invocationId === null ? [] : $units->journal($state->invocationId, self::JOURNAL_LINES);
        $what = $state->job()->value ?? $unit;
        $notification = new Notification(
            false,
            "[ArkHive] {$what} FAILED on {$config->restic->host}",
            "The systemd unit {$unit} failed: {$state->describe()}.",
            [
                'Unit' => $unit,
                'Outcome' => $state->describe(),
                'Started' => $state->started === '' ? 'unknown' : $state->started,
                'Ended' => $state->ended === '' ? 'unknown' : $state->ended,
                'Invocation' => $state->invocationId ?? 'unknown',
                ...$this->commonFacts($config),
                'Log' => $lines === [] ? 'not available (journalctl -u ' . $unit . ')' : 'the last ' . count($lines) . ' lines of the run below',
            ],
            $lines,
        );
        if (!$this->sendNotification($config, $notification)) {
            return $this->notificationFailure?->exitCode() ?? ExitCode::FAILURE;
        }
        $this->log->success("Sent the notification about the failed {$unit}");

        return ExitCode::SUCCESS;
    }

    /**
     * The ArkHive run of this very invocation recorded that it sent its notification.
     */
    private function alreadyNotified(UnitState $state): bool
    {
        $job = $state->job();
        if ($job === null || $state->invocationId === null) {
            return false;
        }
        $last = $this->laravel->make(RunJournal::class)->states()[$job->value]->last ?? null;

        return $last !== null && $last->invocation === $state->invocationId && $last->notified;
    }
}
