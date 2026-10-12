<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Backup\BackupReport;
use Mfonte\Arkhive\Services\Backup\BackupRunner;
use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Dump\DatabaseDumps;
use Mfonte\Arkhive\Services\Notify\Notification;
use Mfonte\Arkhive\Services\Notify\Notifier;
use Mfonte\Arkhive\Services\Restic\ResticFactory;
use Mfonte\Arkhive\Support\Bytes;

final class BackupCommand extends ConfiguredCommand
{
    protected $signature = 'backup
        {--json : Print the snapshot summary as JSON on stdout}
        {--with-progress : (1.x, no longer needed) progress is shown automatically in interactive mode}
        {--with-disk-space-check : (1.x, no longer needed) free space for the dumps is always checked}';

    protected $description = 'Dump the databases and back up the configured paths to the restic repository';

    private Notifier $notifier;

    protected function perform(): int
    {
        foreach (['with-progress' => 'progress is shown automatically in interactive mode', 'with-disk-space-check' => 'free space for the dumps is always checked'] as $flag => $why) {
            if ((bool) $this->option($flag)) {
                $this->log->notice("--{$flag} is a 1.x option and no longer needed: {$why}");
            }
        }
        $config = $this->loadConfig();

        // resolved here, not in the constructor: commands are built when the application boots
        $this->notifier = $this->laravel->make(Notifier::class);
        $runner = new BackupRunner(
            $this->laravel->make(ResticFactory::class),
            $this->laravel->make(DatabaseDumps::class),
            $this->cleanup,
            $this->clock,
            $this->terminal->environment(),
            posix_geteuid(),
        );
        try {
            $report = $runner->run($config, $this->log);
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $this->notify($config, $this->failure($config, $e));

            throw $e;
        }

        $this->log->success(ucfirst($report->summary->describe()), $report->summary->toArray());
        if ((bool) $this->option('json')) {
            $this->result(json_encode([
                ...$report->summary->toArray(),
                'dumps' => array_map(static fn ($dump): array => ['engine' => $dump->engine, 'database' => $dump->database, 'bytes' => $dump->bytes], $report->dumps),
                'warnings' => $report->warnings,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        $exit = $report->hasWarnings() ? ExitCode::WARNING : ExitCode::SUCCESS;
        if (!$this->notify($config, $this->success($config, $report))) {
            $exit = ExitCode::WARNING;
        }

        return $exit;
    }

    /**
     * Sends the notification if configured. A failure is logged, never fatal: returns false.
     */
    private function notify(Config $config, Notification $notification): bool
    {
        if ($config->notify === null || !Notifier::shouldSend($config->notify, $notification)) {
            return true;
        }
        try {
            $this->notifier->send($config->notify, $notification);
            $this->log->debug('Notification sent to ' . implode(', ', $config->notify->to));

            return true;
        } catch (ArkhiveException $e) {
            $this->log->warning($e->getMessage());
            if ($e->hint() !== null) {
                $this->log->notice($e->hint());
            }

            return false;
        }
    }

    private function success(Config $config, BackupReport $report): Notification
    {
        $summary = $report->summary;
        $dumps = array_map(static fn ($dump): string => "{$dump->engine}/{$dump->database} (" . Bytes::format($dump->bytes) . ')', $report->dumps);
        $state = $report->hasWarnings() ? 'completed with warnings' : 'OK';

        return new Notification(
            !$report->hasWarnings(),
            "[ArkHive] backup {$state} on {$config->restic->host}",
            $report->hasWarnings() ? 'The backup completed with warnings: ' . implode('; ', $report->warnings) . '.' : 'The backup completed successfully.',
            [
                ...$this->commonFacts($config),
                'Snapshot' => $summary->snapshotId,
                'Data added' => Bytes::format($summary->dataAdded),
                'Processed' => sprintf('%s in %d files', Bytes::format($summary->totalBytesProcessed), $summary->totalFilesProcessed),
                'Files' => sprintf('%d new, %d changed, %d unmodified', $summary->filesNew, $summary->filesChanged, $summary->filesUnmodified),
                'Dumps' => $dumps === [] ? 'none' : implode(', ', $dumps),
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

    /**
     * @return array<string, string>
     */
    private function commonFacts(Config $config): array
    {
        $version = config('app.version');

        return [
            'Host' => $config->restic->host,
            'Configuration' => $config->source,
            'Repository' => $config->restic->repository,
            'Paths' => implode(' ', $config->backupPaths),
            'Time' => $this->clock->now()->format(DATE_ATOM),
            'ArkHive' => is_string($version) ? $version : 'unknown',
        ];
    }
}
