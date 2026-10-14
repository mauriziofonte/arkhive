<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Maintenance\MaintainRunner;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Maintenance\MaintainReport;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Domain\Repository\CheckResult;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\ExitCode;

final class MaintainCommand extends ConfiguredCommand
{
    protected $signature = 'maintain
        {--json : Print the result as JSON on stdout}';

    protected $description = 'Apply the retention policy (forget --prune), then check the repository and read back a subset of its data';

    protected function perform(): int
    {
        $config = $this->loadConfig();
        $runner = new MaintainRunner(
            $this->laravel->make(SnapshotStoreFactory::class),
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
            $this->sendNotification($config, new Notification(
                false,
                "[ArkHive] maintenance FAILED on {$config->restic->host}",
                'The maintenance failed: ' . $e->getMessage() . ($e->hint() === null ? '' : "\nHint: " . $e->hint()),
                $this->commonFacts($config),
            ));

            throw $e;
        }

        if ((bool) $this->option('json')) {
            $this->result(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $exit = ExitCode::SUCCESS;
        if (!$report->healthy()) {
            $this->log->error('The repository check found errors: ' . $this->failedCheck($report)->describe(), ['broken_packs' => implode(' ', $report->brokenPacks())]);
            $this->log->notice(MaintainRunner::repairHint($report->brokenPacks()));
            $exit = ExitCode::DATA_ERROR;
        } else {
            $this->log->success(sprintf('Maintenance done in %.1fs: the repository is healthy', $report->duration));
        }
        if (!$this->sendNotification($config, $this->notification($config, $report)) && $exit === ExitCode::SUCCESS) {
            $exit = ExitCode::WARNING;
        }

        return $exit;
    }

    private function failedCheck(MaintainReport $report): CheckResult
    {
        return !$report->structure->ok || $report->data === null ? $report->structure : $report->data;
    }

    private function notification(Config $config, MaintainReport $report): Notification
    {
        $healthy = $report->healthy();

        return new Notification(
            $healthy,
            '[ArkHive] maintenance ' . ($healthy ? 'OK' : 'found ERRORS') . " on {$config->restic->host}",
            $healthy
                ? 'The maintenance completed and the repository is healthy.'
                : 'The repository check found errors: ' . $this->failedCheck($report)->describe() . "\nHint: " . MaintainRunner::repairHint($report->brokenPacks()),
            [
                ...$this->commonFacts($config),
                'Policy' => sprintf('%d daily, %d weekly, %d monthly', $config->keepDaily, $config->keepWeekly, $config->keepMonthly),
                'Snapshots' => sprintf('%d kept, %d removed', count($report->forget->kept), count($report->forget->removed)),
                'Check' => $report->structure->describe(),
                'Data read back' => $report->data === null ? 'skipped (the structure check failed)' : "{$report->subset}: {$report->data->describe()}",
                'Repository size' => $report->stats === null ? 'unknown' : Bytes::format($report->stats->rawBytes),
                'Duration' => sprintf('%.1fs', $report->duration),
                'restic' => $report->resticVersion,
            ],
        );
    }
}
