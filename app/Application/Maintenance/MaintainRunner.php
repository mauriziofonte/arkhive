<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Maintenance;

use Mfonte\Arkhive\Application\Backup\BackupRunner;
use Mfonte\Arkhive\Application\Output\Logger;
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
 * spec §6 maintain: forget --prune with the 7/4/6 policy on this host's ArkHive snapshots, then
 * check, then check --read-data-subset. Holds the ArkHive lock so that it never runs during a backup.
 * A damaged repository is reported, never repaired: when prune refuses to run on missing data, the
 * checks still run so that the report names everything wrong (exit 65). A repository locked by
 * another run (a backup of another host) is waited for, up to LOCK_WAIT.
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

        $log->info(sprintf(
            'Applying the retention policy to the snapshots of %s: %d daily, %d weekly, %d monthly',
            $config->restic->host,
            $config->keepDaily,
            $config->keepWeekly,
            $config->keepMonthly,
        ));
        $forgetError = null;
        try {
            $forget = $this->retrier->whileLocked(
                static fn () => $restic->forget($config->restic->host, BackupRunner::TAG, $config->keepDaily, $config->keepWeekly, $config->keepMonthly),
                $lockPolicy,
                'restic forget',
                $log,
            );
            $log->success(
                sprintf('Kept %d snapshots, removed %d and pruned the data they no longer share', count($forget->kept), count($forget->removed)),
                ['kept' => count($forget->kept), 'removed' => count($forget->removed)],
            );
        } catch (DataException $e) {
            // prune refuses to touch a repository with missing data: check it all the same, to say what is wrong
            $forget = new ForgetResult([], []);
            $forgetError = $e->getMessage();
            $log->error("The retention policy was not applied: {$forgetError}");
        }

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
     * What to do about a damaged repository; ArkHive never repairs on its own.
     *
     * @param list<string> $brokenPacks
     */
    public static function repairHint(array $brokenPacks, bool $missingPacks = false, ?string $restic = null): string
    {
        return RepairAdvice::hint($brokenPacks, $missingPacks, $restic);
    }
}
