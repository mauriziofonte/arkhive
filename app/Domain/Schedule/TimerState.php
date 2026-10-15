<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Schedule;

/**
 * One scheduled job as systemd sees it: its timer (installed, enabled, next and last trigger) and
 * how its service last ended.
 */
final readonly class TimerState
{
    /** the `systemctl show` properties of the timer that fromProperties() reads */
    public const PROPERTIES = ['LoadState', 'UnitFileState', 'ActiveState', 'NextElapseUSecRealtime', 'LastTriggerUSec'];

    public function __construct(
        public Job $job,
        public bool $installed,
        public string $enabled,
        public string $active,
        public string $next,
        public string $last,
        public UnitState $service,
    ) {
    }

    /**
     * @param array<string, string> $timer   `systemctl show` of arkhive-<job>.timer
     * @param array<string, string> $service `systemctl show` of arkhive-<job>.service
     */
    public static function fromProperties(Job $job, array $timer, array $service): self
    {
        return new self(
            $job,
            ($timer['LoadState'] ?? '') === 'loaded',
            $timer['UnitFileState'] ?? '',
            $timer['ActiveState'] ?? '',
            UnitState::timestamp($timer['NextElapseUSecRealtime'] ?? ''),
            UnitState::timestamp($timer['LastTriggerUSec'] ?? ''),
            UnitState::fromProperties("arkhive-{$job->value}.service", $service),
        );
    }

    public function ran(): bool
    {
        return $this->service->started !== '';
    }

    /**
     * Started and not ended yet.
     */
    public function running(): bool
    {
        return $this->ran() && $this->service->ended === '';
    }

    /**
     * Its last run ended without success.
     */
    public function failed(): bool
    {
        return $this->ran() && !$this->running() && $this->service->result !== 'success';
    }

    /**
     * Installed, enabled, waiting for its next run, and its last run (if any) succeeded.
     */
    public function healthy(): bool
    {
        return $this->installed && $this->enabled === 'enabled' && $this->active === 'active'
            && !$this->failed();
    }

    /**
     * "enabled", "disabled", "not installed".
     */
    public function describeTimer(): string
    {
        if (!$this->installed) {
            return 'not installed';
        }

        return $this->active === 'active' ? $this->enabled : "{$this->enabled}, {$this->active}";
    }

    public function describeLast(): string
    {
        if (!$this->ran()) {
            return 'never ran';
        }
        if ($this->running()) {
            return 'running';
        }

        return $this->failed() ? 'FAILED: ' . $this->service->describe() : 'succeeded';
    }

    /**
     * @return array{job: string, installed: bool, enabled: string, active: string, next: string|null, last_trigger: string|null, running: bool, last_result: string|null, last_exit_status: int|null, healthy: bool}
     */
    public function toArray(): array
    {
        return [
            'job' => $this->job->value,
            'installed' => $this->installed,
            'enabled' => $this->enabled,
            'active' => $this->active,
            'next' => $this->next === '' ? null : $this->next,
            'last_trigger' => $this->last === '' ? null : $this->last,
            'running' => $this->running(),
            'last_result' => $this->ran() && !$this->running() ? $this->service->result : null,
            'last_exit_status' => $this->ran() && !$this->running() ? $this->service->mainStatus : null,
            'healthy' => $this->healthy(),
        ];
    }
}
