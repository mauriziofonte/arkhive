<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Run\RunJournal;
use Mfonte\Arkhive\Domain\Repository\RepositoryKey;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Run\SnapshotContinuity;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;

/**
 * spec §6 status: latest snapshot and its age, snapshot count, repository size, and the last run of
 * each job on this repository (from the state directory). With --max-age it is a monitoring probe:
 * exit 1 when the latest backup is older than that or missing. Exit 65 when the snapshot of the last
 * successful backup recorded here is gone from the repository (SnapshotContinuity).
 */
final class StatusCommand extends RepositoryCommand
{
    protected $signature = 'status
        {--host= : Snapshots of this host (default: RESTIC_HOST, or this machine)}
        {--max-age= : Exit 1 when the latest snapshot is older than this many hours (monitoring)}
        {--json : Print the status as JSON on stdout}';

    protected $description = 'Show the latest snapshot, its age, the snapshot count and the repository size';

    protected function perform(): int
    {
        $maxAge = $this->maxAge();
        [$settings, $config] = $this->repositorySettings();
        $restic = $this->openRepository($settings, 'to read the repository status');
        $all = new Snapshots($restic->snapshots());
        $host = $this->hostOption() ?? $config?->restic->host;
        if ($host === null && in_array(self::hostname(), $all->hosts(), true)) {
            $host = self::hostname();
        }
        $backups = $all->backups()->forHost($host);
        $latest = $backups->snapshots[0] ?? null;
        $stats = $restic->stats();
        $now = $this->clock->now();
        $key = RepositoryKey::of($settings->repository);
        $runs = $this->laravel->make(RunJournal::class)->states($key);
        $continuity = SnapshotContinuity::check($runs['backup']->lastSuccess ?? null, $key, $all);
        $age = $latest === null ? null : round(max(0, $now->getTimestamp() - $latest->time->getTimestamp()) / 3600, 1);

        $status = [
            'repository' => $settings->repository,
            'host' => $host,
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'short_id' => $latest->shortId(),
                'time' => $latest->time->format(DATE_ATOM),
                'age_hours' => $age,
            ],
            'snapshots' => count($backups->snapshots),
            'snapshots_total' => count($all->snapshots),
            'hosts' => $all->hosts(),
            'raw_size' => $stats->rawBytes,
            'uncompressed_size' => $stats->uncompressedBytes,
            'runs' => array_map(static fn ($state): array => $state->toArray(), $runs),
            'continuity' => $continuity->toArray(),
        ];
        if ((bool) $this->option('json')) {
            $this->result(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $this->result($this->describe($status, $latest, $now, $runs, $continuity));
        }

        $missing = $continuity->message($now->getTimezone());
        if ($missing !== null) {
            $this->log->error(ucfirst($missing), $continuity->toArray());
            $this->log->notice('Hint: ' . SnapshotContinuity::HINT);

            return ExitCode::DATA_ERROR;
        }

        if ($maxAge === null) {
            return ExitCode::SUCCESS;
        }
        if ($latest === null) {
            $this->log->error('No snapshot' . ($host === null ? '' : " of {$host}") . ' in the repository', ['max_age' => $maxAge]);

            return ExitCode::FAILURE;
        }
        if ($age > $maxAge) {
            $this->log->error(sprintf('The latest snapshot %s is %.1f hours old, more than --max-age %s', $latest->shortId(), $age, self::number($maxAge)), ['age_hours' => $age, 'max_age' => $maxAge]);

            return ExitCode::FAILURE;
        }

        return ExitCode::SUCCESS;
    }

    private function maxAge(): ?float
    {
        $value = self::stringOption($this->option('max-age'));
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1 || (float) $value <= 0) {
            throw new UsageException("--max-age must be a number of hours greater than 0, not '{$value}'", 'For a daily backup: --max-age 26');
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed>                                $status
     * @param array<string, \Mfonte\Arkhive\Domain\Run\JobState> $runs
     */
    private function describe(array $status, ?Snapshot $latest, \DateTimeImmutable $now, array $runs, SnapshotContinuity $continuity): string
    {
        $rows = [
            'Repository' => (string) $status['repository'],
            'Host' => $status['host'] === null ? 'all hosts' : (string) $status['host'],
            'Latest snapshot' => $latest === null
                ? 'none yet'
                : sprintf('%s at %s (%s ago)', $latest->shortId(), $latest->time->setTimezone($now->getTimezone())->format('Y-m-d H:i T'), $latest->age($now)),
            'Snapshots' => sprintf('%d', $status['snapshots']) . ($status['snapshots'] === $status['snapshots_total'] ? '' : sprintf(' (%d in the repository)', $status['snapshots_total'])),
            'Repository size' => Bytes::format((int) $status['raw_size']),
        ];
        foreach ($runs as $job => $state) {
            if ($state->last === null) {
                continue;
            }
            $rows["Last {$job}"] = $state->last->describe($now->getTimezone());
            if (!$state->last->succeeded()) {
                $rows["Last good {$job}"] = $state->lastSuccess === null ? 'none recorded' : $state->lastSuccess->describe($now->getTimezone());
            }
        }
        if ($continuity->broken()) {
            $rows['Last good snapshot'] = sprintf('%s MISSING from the repository (deleted or rolled back)', substr((string) $continuity->recorded?->snapshotId, 0, 8));
        }
        $width = max(array_map('strlen', array_keys($rows)));
        $lines = [];
        foreach ($rows as $label => $value) {
            $lines[] = str_pad($label . ':', $width + 2) . $value;
        }

        return implode("\n", $lines);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.1f', $value), '0'), '.');
    }
}
