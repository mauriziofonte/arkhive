<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Backup;

use Mfonte\Arkhive\Application\Database\DatabaseDumps;
use Mfonte\Arkhive\Application\Database\DumpRun;
use Mfonte\Arkhive\Application\Database\SecretFile;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Backup\BackupReport;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Domain\Repository\BackupRequest;
use Mfonte\Arkhive\Domain\Repository\BackupStatus;
use Mfonte\Arkhive\Domain\Repository\BackupSummary;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use Mfonte\Arkhive\Exceptions\SoftwareException;

/**
 * spec §6 backup: preflight (restic and its version, lock, repository reachable, dump space) →
 * dumps → restic backup --json → summary. A failed dump does not stop the others nor the backup of
 * the files: it is a warning, or the run's failure with DUMP_REQUIRED=true. restic exit 3 (snapshot saved, some files unreadable) is a
 * warning. The dump directory is emptied and the lock released however the run ends. A repository
 * locked by another run is waited for (LOCK_WAIT); network errors while opening it are retried.
 */
final class BackupRunner
{
    public const TAG = 'arkhive';

    private const MAX_LISTED_WARNINGS = 20;

    /**
     * @param array<string, string> $environment for the lock directory
     */
    public function __construct(
        private readonly SnapshotStoreFactory $restic,
        private readonly DatabaseDumps $dumps,
        private readonly Cleanup $cleanup,
        private readonly Clock $clock,
        private readonly array $environment,
        private readonly int $uid,
        private readonly Retrier $retrier,
    ) {
    }

    public function run(Config $config, Logger $log): BackupReport
    {
        $started = hrtime(true);
        $restic = $this->restic->create($config->restic, 'to back up');
        $version = $restic->requireSupportedVersion();
        $log->debug("Using {$restic->binary()} (restic {$version})");

        $lockPolicy = RetryPolicy::lock($config->restic->lockWait);
        $lock = Lock::forRepository($config->restic->repository, $this->environment, $this->clock, $this->uid);
        $stale = $this->retrier->whileLocked(static fn (): ?string => $lock->acquire('backup'), $lockPolicy, 'Waiting for the repository', $log);
        $this->cleanup->add('release the lock', $lock->release(...));
        if ($stale !== null) {
            $log->warning($stale);
        }

        $log->info("Opening the repository {$config->restic->repository}");
        $this->retrier->transient($restic->checkReachable(...), $lockPolicy, 'Opening the repository', $log);

        $dumps = $this->dumps->run($config, $log);
        try {
            [$summary, $warnings] = $this->backup($restic, $config, $dumps, $log, $lockPolicy);
        } finally {
            if ($dumps !== null) {
                $this->dumps->clear($dumps);
            }
        }

        $failures = $dumps->failures ?? [];
        foreach ($failures as $failure) {
            $warnings[] = $failure->describe() . ' failed: ' . $failure->error;
        }
        $report = new BackupReport($summary, $dumps->results ?? [], $version, $warnings, (hrtime(true) - $started) / 1e9, $failures, $config->dumpRequired);
        if ($report->failed()) {
            $log->error(sprintf('%d database dump(s) failed and DUMP_REQUIRED is true: the files are in snapshot %s, those databases are not', count($failures), $summary->shortId()));
        }

        return $report;
    }

    /**
     * @return array{BackupSummary, list<string>}
     */
    private function backup(SnapshotStore $restic, Config $config, ?DumpRun $dumps, Logger $log, RetryPolicy $lockPolicy): array
    {
        $excludes = SecretFile::create('arkhive-exclude-', ExclusionConverter::excludeFile($config->resticExcludes));
        $cleanupId = $this->cleanup->add('remove the exclude file', $excludes->remove(...));
        $paths = $config->backupPaths;
        if ($dumps !== null) {
            $paths[] = $dumps->staging->directory;
        }
        $request = new BackupRequest(
            $paths,
            $config->restic->host,
            [self::TAG],
            'restic backup',
            compression: $config->restic->compression,
            excludeFile: $excludes->path,
            allowIncomplete: true,
            showProgress: $log->wantsProgress(),
        );

        $log->info('Backing up ' . implode(' ', $paths));
        try {
            $outcome = $this->retrier->whileLocked(
                static fn () => $restic->backup($request, static function (BackupStatus $status) use ($log): void {
                    $log->progress($status->describe());
                }),
                $lockPolicy,
                'restic backup',
                $log,
            );
        } finally {
            $log->endProgress();
            $this->cleanup->runOne($cleanupId);
        }

        $summary = $outcome->summary;
        if ($summary === null || $outcome->snapshotId() === null) {
            throw new SoftwareException('restic backup ended without reporting a snapshot' . ($outcome->diagnostics === '' ? '' : ': ' . $outcome->diagnostics));
        }

        $warnings = [];
        if ($outcome->incomplete) {
            $unreadable = $outcome->unreadable;
            foreach (array_slice($unreadable, 0, self::MAX_LISTED_WARNINGS) as $item) {
                $log->warning("Not backed up: {$item}");
            }
            if (count($unreadable) > self::MAX_LISTED_WARNINGS) {
                $log->warning(sprintf('... and %d more files that could not be read', count($unreadable) - self::MAX_LISTED_WARNINGS));
            }
            $warnings[] = sprintf('the snapshot was saved but %d source item(s) could not be read', max(1, count($unreadable)));
        }

        return [$summary, $warnings];
    }
}
