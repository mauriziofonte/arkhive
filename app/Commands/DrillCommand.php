<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Drill\DrillRunner;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Drill\DrillReport;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Random\Randomizer;

/**
 * spec §10.5: an automated restore test of the latest snapshot, meant for a monthly timer.
 */
final class DrillCommand extends RepositoryCommand
{
    /** a sample larger than this is a full restore: use arkhive restore */
    public const MAX_SAMPLE = 100000;

    protected $signature = 'drill
        {--sample=50 : Number of random files to restore and verify}
        {--db : Also restore one database dump and import it into a throw-away database}
        {--host= : Test the latest snapshot of this host (default: RESTIC_HOST, or this machine)}
        {--json : Print the result as JSON on stdout}';

    protected $description = 'Restore a random sample of the latest snapshot into a temporary directory and verify it (exit 0 pass, 65 fail)';

    #[\Override]
    protected function recordedJob(): string
    {
        return 'drill';
    }

    protected function perform(): int
    {
        $sample = $this->sample();
        [$settings, $config] = $this->repositorySettings();
        try {
            $restic = $this->openRepository($settings, 'to run a restore drill');
            $host = $this->hostOption() ?? $config?->restic->host;
            $snapshot = (new Snapshots($restic->snapshots()))->resolve('latest', $host, self::hostname());
            $runner = new DrillRunner(
                $this->laravel->make(ProcessRunner::class),
                $this->laravel->make(Dependencies::class),
                $this->cleanup,
                $this->laravel->make(Randomizer::class),
                sys_get_temp_dir(),
            );
            $report = $runner->run($restic, $snapshot, $sample, $config, (bool) $this->option('db'), $this->log);
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            if ($config !== null && !$e instanceof UsageException && !$e instanceof ConfigException) {
                $this->sendNotification($config, new Notification(
                    false,
                    "[ArkHive] restore drill FAILED on {$config->restic->host}",
                    'The restore drill could not run: ' . $e->getMessage() . ($e->hint() === null ? '' : "\nHint: " . $e->hint()),
                    $this->commonFacts($config),
                ));
            }

            throw $e;
        }

        $this->runSnapshot = $report->snapshot->id;
        if ((bool) $this->option('json')) {
            $this->result(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if ($report->passed()) {
            $this->log->success(sprintf('Restore drill PASSED on snapshot %s: %s', $report->snapshot->shortId(), $this->summary($report)));
        } else {
            $this->log->error(sprintf('Restore drill FAILED on snapshot %s: %d problem(s)', $report->snapshot->shortId(), count($report->failures) + ($report->database !== null && !$report->database['ok'] ? 1 : 0)));
        }
        $exit = $report->passed() ? ExitCode::SUCCESS : ExitCode::DATA_ERROR;
        if ($config !== null && !$this->sendNotification($config, $this->notification($config, $report)) && $exit === ExitCode::SUCCESS) {
            $exit = ExitCode::WARNING;
        }

        return $exit;
    }

    /**
     * @return positive-int
     */
    private function sample(): int
    {
        $value = self::stringOption($this->option('sample')) ?? '';
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1 || (int) $value > self::MAX_SAMPLE) {
            throw new UsageException(sprintf("--sample must be a whole number from 1 to %d, not '%s'", self::MAX_SAMPLE, $value), 'For example: arkhive drill --sample ' . DrillRunner::DEFAULT_SAMPLE);
        }

        return max(1, (int) $value);
    }

    private function summary(DrillReport $report): string
    {
        $text = sprintf('%d of %d files (%s) restored and verified', count($report->files), $report->available, Bytes::format($report->bytes));
        if ($report->database !== null) {
            $text .= sprintf(', %s/%s imported into a throw-away database', $report->database['engine'], $report->database['database']);
        }

        return $text . sprintf(' in %.1fs', $report->duration);
    }

    private function notification(Config $config, DrillReport $report): Notification
    {
        $passed = $report->passed();
        $problems = $report->failures;
        if ($report->database !== null && $report->database['error'] !== null) {
            $problems[] = $report->database['error'];
        }

        return new Notification(
            $passed,
            '[ArkHive] restore drill ' . ($passed ? 'PASSED' : 'FAILED') . " on {$config->restic->host}",
            $passed ? 'The restore drill passed: ' . $this->summary($report) . '.' : "The restore drill failed:\n  " . implode("\n  ", array_slice($problems, 0, 20)),
            [
                ...$this->commonFacts($config),
                'Snapshot' => $report->snapshot->id . ' (' . $report->snapshot->time->format(DATE_ATOM) . ')',
                'Files tested' => sprintf('%d of %d (%s)', count($report->files), $report->available, Bytes::format($report->bytes)),
                'Database' => $report->database === null ? 'not tested (no --db)' : ($report->database['database'] === '' ? 'no dump' : "{$report->database['engine']}/{$report->database['database']}: " . ($report->database['ok'] ? 'imported' : 'FAILED')),
                'Duration' => sprintf('%.1fs', $report->duration),
            ],
        );
    }
}
