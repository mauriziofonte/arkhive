<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Backup\BackupRunner;
use Mfonte\Arkhive\Application\Database\DatabaseDumps;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Domain\Backup\BackupReport;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\ExitCode;

final class BackupCommand extends ConfiguredCommand
{
    protected $signature = 'backup
        {--json : Print the snapshot summary as JSON on stdout}
        {--with-progress : (1.x, no longer needed) progress is shown automatically in interactive mode}
        {--with-disk-space-check : (1.x, no longer needed) free space for the dumps is always checked}';

    protected $description = 'Dump the databases and back up the configured paths to the restic repository';

    #[\Override]
    protected function recordedJob(): string
    {
        return 'backup';
    }

    protected function perform(): int
    {
        foreach (['with-progress' => 'progress is shown automatically in interactive mode', 'with-disk-space-check' => 'free space for the dumps is always checked'] as $flag => $why) {
            if ((bool) $this->option($flag)) {
                $this->log->notice("--{$flag} is a 1.x option and no longer needed: {$why}");
            }
        }
        $config = $this->loadConfig();

        $runner = new BackupRunner(
            $this->laravel->make(SnapshotStoreFactory::class),
            $this->laravel->make(DatabaseDumps::class),
            $this->cleanup,
            $this->clock,
            $this->terminal->environment(),
            posix_geteuid(),
            $this->laravel->make(Retrier::class),
        );
        try {
            $report = $runner->run($config, $this->log);
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $this->sendNotification($config, $this->failure($config, $e));

            throw $e;
        }

        $this->runSnapshot = $report->summary->snapshotId;
        $this->runWarnings = $report->warnings;
        $this->log->success(ucfirst($report->summary->describe()), $report->summary->toArray());
        if ((bool) $this->option('json')) {
            $this->result(json_encode([
                ...$report->summary->toArray(),
                'dumps' => array_map(static fn ($dump): array => ['engine' => $dump->engine, 'database' => $dump->database, 'bytes' => $dump->bytes], $report->dumps),
                'dump_failures' => array_map(static fn ($failure): array => $failure->toArray(), $report->dumpFailures),
                'warnings' => $report->warnings,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        $exit = $report->exitCode();
        if (!$this->sendNotification($config, $this->success($config, $report)) && $exit === ExitCode::SUCCESS) {
            $exit = ExitCode::WARNING;
        }

        return $exit;
    }

    private function success(Config $config, BackupReport $report): Notification
    {
        $summary = $report->summary;
        $dumps = array_map(static fn ($dump): string => "{$dump->engine}/{$dump->database} (" . Bytes::format($dump->bytes) . ')', $report->dumps);
        $failures = array_map(static fn ($failure): string => "{$failure->engine}/" . ($failure->database ?? '*'), $report->dumpFailures);
        [$state, $headline] = match (true) {
            $report->failed() => ['FAILED', "The backup saved the files in snapshot {$summary->shortId()}, but database dumps required by DUMP_REQUIRED failed: " . implode('; ', $report->warnings) . '.'],
            $report->hasWarnings() => ['completed with warnings', 'The backup completed with warnings: ' . implode('; ', $report->warnings) . '.'],
            default => ['OK', 'The backup completed successfully.'],
        };

        return new Notification(
            !$report->hasWarnings(),
            "[ArkHive] backup {$state} on {$config->restic->host}",
            $headline,
            [
                ...$this->commonFacts($config),
                'Snapshot' => $summary->snapshotId,
                'Data added' => Bytes::format($summary->dataAdded),
                'Processed' => sprintf('%s in %d files', Bytes::format($summary->totalBytesProcessed), $summary->totalFilesProcessed),
                'Files' => sprintf('%d new, %d changed, %d unmodified', $summary->filesNew, $summary->filesChanged, $summary->filesUnmodified),
                'Dumps' => $dumps === [] ? 'none' : implode(', ', $dumps),
                ...($failures === [] ? [] : ['Failed dumps' => implode(', ', $failures)]),
                'Duration' => sprintf('%.1fs', $report->duration),
                'restic' => $report->resticVersion,
            ],
        );
    }

    private function failure(Config $config, ArkhiveException $e): Notification
    {
        return new Notification(
            false,
            "[ArkHive] backup FAILED on {$config->restic->host}",
            'The backup failed: ' . $e->getMessage() . ($e->hint() === null ? '' : "\nHint: " . $e->hint()),
            $this->commonFacts($config),
        );
    }
}
