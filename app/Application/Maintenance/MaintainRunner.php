<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Maintenance;

use Mfonte\Arkhive\Application\Backup\BackupRunner;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Maintenance\MaintainReport;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;

/**
 * spec §6 maintain: forget --prune with the 7/4/6 policy on this host's ArkHive snapshots, then
 * check, then check --read-data-subset. Holds the ArkHive lock so that it never runs during a backup.
 * A damaged repository is reported, never repaired.
 */
final class MaintainRunner
{
    public const TROUBLESHOOTING_URL = 'https://restic.readthedocs.io/en/stable/077_troubleshooting.html';

    /**
     * @param array<string, string> $environment for the lock directory
     */
    public function __construct(
        private readonly SnapshotStoreFactory $restic,
        private readonly Cleanup $cleanup,
        private readonly Clock $clock,
        private readonly array $environment,
        private readonly int $uid,
    ) {
    }

    public function run(Config $config, Logger $log): MaintainReport
    {
        $started = hrtime(true);
        $restic = $this->restic->create($config->restic, 'to maintain the repository');
        $version = $restic->requireSupportedVersion();
        $log->debug("Using {$restic->binary()} (restic {$version})");

        $lock = Lock::forRepository($config->restic->repository, $this->environment, $this->clock, $this->uid);
        $stale = $lock->acquire('maintain');
        $this->cleanup->add('release the lock', $lock->release(...));
        if ($stale !== null) {
            $log->warning($stale);
        }

        $log->info("Opening the repository {$config->restic->repository}");
        $restic->checkReachable();

        $log->info(sprintf(
            'Applying the retention policy to the snapshots of %s: %d daily, %d weekly, %d monthly',
            $config->restic->host,
            $config->keepDaily,
            $config->keepWeekly,
            $config->keepMonthly,
        ));
        $forget = $restic->forget($config->restic->host, BackupRunner::TAG, $config->keepDaily, $config->keepWeekly, $config->keepMonthly);
        $log->success(
            sprintf('Kept %d snapshots, removed %d and pruned the data they no longer share', count($forget->kept), count($forget->removed)),
            ['kept' => count($forget->kept), 'removed' => count($forget->removed)],
        );

        $log->info('Checking the repository structure');
        $structure = $restic->check();
        $data = null;
        if ($structure->ok) {
            $log->success('Repository structure: ' . $structure->describe());
            $log->info("Reading back {$config->checkSubset} of the stored data");
            $data = $restic->check($config->checkSubset);
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

        return new MaintainReport($forget, $structure, $data, $config->checkSubset, $stats, $version, (hrtime(true) - $started) / 1e9);
    }

    /**
     * What to do about a damaged repository; ArkHive never repairs on its own.
     *
     * @param list<string> $brokenPacks
     */
    public static function repairHint(array $brokenPacks): string
    {
        $packs = $brokenPacks === [] ? '' : ' then: restic repair packs ' . implode(' ', $brokenPacks) . ' and restic repair snapshots --forget;';

        return 'Stop and read ' . self::TROUBLESHOOTING_URL . ' first;' . $packs
            . ' back up again afterwards (ArkHive never repairs a repository automatically)';
    }
}
