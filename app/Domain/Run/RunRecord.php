<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Run;

use DateTimeImmutable;
use Mfonte\Arkhive\Domain\Repository\RepositoryKey;
use Mfonte\Arkhive\ExitCode;

/**
 * One run of a scheduled job (backup, maintain, drill), as kept in the state directory: when it ran,
 * how it ended, and what it produced.
 */
final readonly class RunRecord
{
    public const JOBS = ['backup', 'maintain', 'drill'];

    /**
     * @param list<string> $warnings
     * @param string|null  $invocation systemd's INVOCATION_ID of the run, when it ran as a unit
     * @param bool         $notified   the run sent its notification e-mail
     * @param string|null  $repository the repository it worked on (RepositoryKey); null when it never
     *                                 got that far, and in the records of 2.1.0-rc.1
     * @param string|null  $host       the host whose snapshots it made or tested (RESTIC_HOST)
     */
    public function __construct(
        public string $job,
        public DateTimeImmutable $started,
        public DateTimeImmutable $ended,
        public int $exitCode,
        public ?string $snapshotId = null,
        public array $warnings = [],
        public ?string $error = null,
        public ?string $invocation = null,
        public bool $notified = false,
        public ?string $repository = null,
        public ?string $host = null,
    ) {
    }

    /**
     * The job did its work: exit 0, or 3 (done, with warnings).
     */
    public function succeeded(): bool
    {
        return $this->exitCode === ExitCode::SUCCESS || $this->exitCode === ExitCode::WARNING;
    }

    public function status(): string
    {
        return match (true) {
            $this->exitCode === ExitCode::SUCCESS => 'ok',
            $this->exitCode === ExitCode::WARNING => 'warning',
            default => 'failed',
        };
    }

    public function duration(): float
    {
        return max(0.0, (float) $this->ended->format('U.u') - (float) $this->started->format('U.u'));
    }

    /**
     * One line for status and doctor: "2026-10-12 03:00 UTC, ok (snapshot e82340a7)".
     */
    public function describe(\DateTimeZone $zone): string
    {
        $what = match ($this->status()) {
            'ok' => 'OK',
            'warning' => 'completed with warnings',
            default => "FAILED with exit {$this->exitCode}" . ($this->error === null ? '' : ": {$this->error}"),
        };
        $snapshot = $this->snapshotId === null ? '' : ', snapshot ' . substr($this->snapshotId, 0, 8);

        return $this->started->setTimezone($zone)->format('Y-m-d H:i T') . ", {$what}{$snapshot}";
    }

    /**
     * @return array{job: string, started: string, ended: string, duration_seconds: float, exit_code: int, status: string, snapshot_id: string|null, warnings: list<string>, error: string|null, invocation: string|null, notified: bool, repository: string|null, host: string|null}
     */
    public function toArray(): array
    {
        return [
            'job' => $this->job,
            'started' => $this->started->format(DATE_ATOM),
            'ended' => $this->ended->format(DATE_ATOM),
            'duration_seconds' => round($this->duration(), 1),
            'exit_code' => $this->exitCode,
            'status' => $this->status(),
            'snapshot_id' => $this->snapshotId,
            'warnings' => $this->warnings,
            'error' => $this->error,
            'invocation' => $this->invocation,
            'notified' => $this->notified,
            'repository' => $this->repository,
            'host' => $this->host,
        ];
    }

    /**
     * The record from its stored form; null when anything is missing or of the wrong type.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)
            || !is_string($data['job'] ?? null)
            || !is_string($data['started'] ?? null)
            || !is_string($data['ended'] ?? null)
            || !is_int($data['exit_code'] ?? null)) {
            return null;
        }
        $started = DateTimeImmutable::createFromFormat(DATE_ATOM, $data['started']);
        $ended = DateTimeImmutable::createFromFormat(DATE_ATOM, $data['ended']);
        if ($started === false || $ended === false) {
            return null;
        }
        $warnings = is_array($data['warnings'] ?? null) ? array_values(array_filter($data['warnings'], 'is_string')) : [];
        $snapshot = $data['snapshot_id'] ?? null;
        $error = $data['error'] ?? null;
        $invocation = $data['invocation'] ?? null;
        $repository = $data['repository'] ?? null;
        $host = $data['host'] ?? null;

        return new self(
            $data['job'],
            $started,
            $ended,
            $data['exit_code'],
            is_string($snapshot) ? $snapshot : null,
            $warnings,
            is_string($error) ? $error : null,
            is_string($invocation) ? $invocation : null,
            ($data['notified'] ?? false) === true,
            is_string($repository) && RepositoryKey::valid($repository) ? $repository : null,
            is_string($host) && $host !== '' ? $host : null,
        );
    }
}
