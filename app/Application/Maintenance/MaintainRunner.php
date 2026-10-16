<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Maintenance;

use Mfonte\Arkhive\Application\Backup\BackupRunner;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Maintenance\MaintainReport;
use Mfonte\Arkhive\Domain\Repository\ForgetResult;
use Mfonte\Arkhive\Domain\Repository\RepairAdvice;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\InterruptedException;

/**
 * spec §6 maintain: check, then check --read-data-subset, then forget with the 7/4/6 policy on this
 * host's ArkHive snapshots, then prune. Holds the ArkHive lock so that it never runs during a backup.
 * A damaged repository is reported, never repaired, and keeps all its snapshots: forget runs only
 * after both checks pass (exit 65 otherwise). A repository locked by another run (a backup of another
 * host) is waited for, up to LOCK_WAIT.
 */
final class MaintainRunner
{
    public const TROUBLESHOOTING_URL = RepairAdvice::TROUBLESHOOTING_URL;

    /**
     * @param array<string, string> $environment for the lock directory
     */
    public function __construct(
        private readonly SnapshotStoreFactory $restic,
        private readonly Cleanup $cleanup,
        private readonly Clock $clock,
        private readonly array $environment,
        private readonly int $uid,
        private readonly Retrier $retrier,
    ) {
    }

    public function run(Config $config, Logger $log): MaintainReport
    {
        $started = hrtime(true);
        $restic = $this->restic->create($config->restic, 'to maintain the repository');
        $version = $restic->requireSupportedVersion();
        $log->debug("Using {$restic->binary()} (restic {$version})");

        $lockPolicy = RetryPolicy::lock($config->restic->lockWait);
        $lock = Lock::forRepository($config->restic->repository, $this->environment, $this->clock, $this->uid);
        $stale = $this->retrier->whileLocked(static fn (): ?string => $lock->acquire('maintain'), $lockPolicy, 'Waiting for the repository', $log);
        $this->cleanup->add('release the lock', $lock->release(...));
        if ($stale !== null) {
            $log->warning($stale);
        }

        $log->info("Opening the repository {$config->restic->repository}");
        $this->retrier->transient($restic->checkReachable(...), $lockPolicy, 'Opening the repository', $log);

        $log->info('Checking the repository structure');
        $structure = $this->retrier->whileLocked(static fn () => $restic->check(), $lockPolicy, 'restic check', $log);
        $data = null;
        if ($structure->ok) {
            $log->success('Repository structure: ' . $structure->describe());
            $log->info("Reading back {$config->checkSubset} of the stored data");
            $data = $this->retrier->whileLocked(static fn () => $restic->check($config->checkSubset), $lockPolicy, 'restic check', $log);
            if ($data->ok) {
                $log->success("Data read back ({$config->checkSubset}): " . $data->describe());
            }
        }

        $forget = null;
        $forgetError = null;
        if ($structure->ok && $data !== null && $data->ok) {
            [$forget, $forgetError] = $this->applyRetention($restic, $config, $lockPolicy, $log);
        } else {
            // a damaged repository keeps every snapshot: an older one may hold the only intact copy of some data
            $log->warning('The retention policy was not applied: ' . MaintainReport::RETENTION_SKIPPED);
        }

        $stats = null;
        try {
            $stats = $restic->stats();
            $log->info('Repository size: ' . Bytes::format($stats->rawBytes), ['raw_size' => $stats->rawBytes]);
        } catch (InterruptedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $log->warning("Could not measure the repository: {$e->getMessage()}");
        }

        return new MaintainReport($forget, $structure, $data, $config->checkSubset, $stats, $version, (hrtime(true) - $started) / 1e9, $forgetError);
    }

    /**
     * forget, then prune, as two runs: when prune refuses to run (missing data), the snapshots
     * forget already removed are still reported.
     *
     * @return array{?ForgetResult, ?string} what forget removed (null when it did not run), why
     *                                       forget or prune refused to run
     */
    private function applyRetention(SnapshotStore $restic, Config $config, RetryPolicy $lockPolicy, Logger $log): array
    {
        $log->info(sprintf(
            'Applying the retention policy to the snapshots of %s: %d daily, %d weekly, %d monthly',
            $config->restic->host,
            $config->keepDaily,
            $config->keepWeekly,
            $config->keepMonthly,
        ));
        try {
            $forget = $this->retrier->whileLocked(
                static fn (): ForgetResult => $restic->forget($config->restic->host, BackupRunner::TAG, $config->keepDaily, $config->keepWeekly, $config->keepMonthly),
                $lockPolicy,
                'restic forget',
                $log,
            );
        } catch (DataException $e) {
            $log->error("The retention policy was not applied: {$e->getMessage()}");

            return [null, $e->getMessage()];
        }
        $log->success(
            sprintf('Kept %d snapshots, removed %d', count($forget->kept), count($forget->removed)),
            ['kept' => count($forget->kept), 'removed' => count($forget->removed)],
        );
        $log->info('Pruning the data no snapshot uses any more');
        try {
            $this->retrier->whileLocked(static function () use ($restic): void {
                $restic->prune();
            }, $lockPolicy, 'restic prune', $log);
        } catch (DataException $e) {
            $log->error("The unused data was not pruned: {$e->getMessage()}");

            return [$forget, $e->getMessage()];
        }
        $log->success('Pruned the data no snapshot uses any more');

        return [$forget, null];
    }

    /**
     * What to do about a damaged repository; ArkHive never repairs on its own.
     *
     * @param list<string> $brokenPacks
     */
    public static function repairHint(array $brokenPacks, bool $missingPacks = false, ?string $restic = null): string
    {
        return RepairAdvice::hint($brokenPacks, $missingPacks, $restic);
    }
}
