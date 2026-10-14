<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Backup;

use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Dump\DatabaseDumps;
use Mfonte\Arkhive\Services\Dump\DumpRun;
use Mfonte\Arkhive\Services\Dump\SecretFile;
use Mfonte\Arkhive\Services\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Restic\BackupStatus;
use Mfonte\Arkhive\Services\Restic\BackupSummary;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restic\ResticFactory;
use Mfonte\Arkhive\Services\Restic\ResticJson;
use Mfonte\Arkhive\Services\System\Lock;
use Mfonte\Arkhive\Support\Clock;

/**
 * spec §6 backup: preflight (restic and its version, lock, repository reachable, dump space) →
 * dumps → restic backup --json → summary. restic exit 3 (snapshot saved, some files unreadable) is a
 * warning. The dump directory is emptied and the lock released however the run ends.
 */
final class BackupRunner
{
    public const TAG = 'arkhive';

    /** restic exit code: snapshot created, but some source files could not be read */
    public const INCOMPLETE = 3;

    private const MAX_LISTED_WARNINGS = 20;

    /**
     * @param array<string, string> $environment for the lock directory
     */
    public function __construct(
        private readonly ResticFactory $restic,
        private readonly DatabaseDumps $dumps,
        private readonly Cleanup $cleanup,
        private readonly Clock $clock,
        private readonly array $environment,
        private readonly int $uid,
    ) {
    }

    public function run(Config $config, Logger $log): BackupReport
    {
        $started = hrtime(true);
        $restic = $this->restic->create($config->restic, 'to back up');
        $version = $restic->requireSupportedVersion();
        $log->debug("Using {$restic->binary} (restic {$version})");

        $lock = Lock::forRepository($config->restic->repository, $this->environment, $this->clock, $this->uid);
        $stale = $lock->acquire('backup');
        $this->cleanup->add('release the lock', $lock->release(...));
        if ($stale !== null) {
            $log->warning($stale);
        }

        $log->info("Opening the repository {$config->restic->repository}");
        $restic->checkReachable();

        $dumps = $this->dumps->run($config, $log);
        try {
            [$summary, $warnings] = $this->backup($restic, $config, $dumps, $log);
        } finally {
            if ($dumps !== null) {
                $this->dumps->clear($dumps);
            }
        }

        return new BackupReport($summary, $dumps->results ?? [], $version, $warnings, (hrtime(true) - $started) / 1e9);
    }

    /**
     * @return array{BackupSummary, list<string>}
     */
    private function backup(Restic $restic, Config $config, ?DumpRun $dumps, Logger $log): array
    {
        $excludes = SecretFile::create('arkhive-exclude-', ExclusionConverter::excludeFile($config->resticExcludes));
        $cleanupId = $this->cleanup->add('remove the exclude file', $excludes->remove(...));
        $paths = $config->backupPaths;
        if ($dumps !== null) {
            $paths[] = $dumps->staging->directory;
        }
        $args = [
            'backup', '--json',
            '--tag', self::TAG,
            '--host', $config->restic->host,
            '--compression', $config->restic->compression,
            '--exclude-file', $excludes->path,
            '--',
            ...$paths,
        ];

        $summary = null;
        $unreadable = [];
        $interactive = $log->context()->interactive;
        $log->info('Backing up ' . implode(' ', $paths));
        try {
            $result = $restic->run(
                $args,
                'restic backup',
                $interactive ? ['RESTIC_PROGRESS_FPS' => '2'] : [],
                static function (string $line) use (&$summary, &$unreadable, $log): void {
                    $data = ResticJson::decode($line);
                    if ($data === null) {
                        return;
                    }
                    $type = ResticJson::string($data, 'message_type');
                    if ($type === 'status') {
                        $log->progress(BackupStatus::fromJson($data)->describe());
                    } elseif ($type === 'summary') {
                        $summary = BackupSummary::fromJson($data);
                    } elseif ($type === 'error') {
                        $error = is_array($data['error'] ?? null) ? ResticJson::string($data['error'], 'message') : '';
                        $item = ResticJson::string($data, 'item');
                        $unreadable[] = trim(($item === '' ? '' : "{$item}: ") . $error);
                    }
                },
                [0, self::INCOMPLETE],
            );
        } finally {
            $log->endProgress();
            $this->cleanup->runOne($cleanupId);
        }

        if (!$summary instanceof BackupSummary || $summary->snapshotId === '') {
            throw new SoftwareException('restic backup ended without reporting a snapshot' . ($result->stderrTail() === '' ? '' : ': ' . $result->stderrTail()));
        }

        $warnings = [];
        if ($result->exitCode === self::INCOMPLETE) {
            foreach (preg_split('/\R/', $result->stderr) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && ResticJson::decode($line) === null) {
                    $unreadable[] = $line;
                }
            }
            $unreadable = array_values(array_unique($unreadable));
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
