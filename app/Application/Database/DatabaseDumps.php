<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Throwable;

/**
 * Runs every configured dump into DUMP_DIRECTORY before restic backup. Any failure removes the dumps
 * already written and aborts, so no snapshot is ever taken with partial or missing dumps.
 */
final class DatabaseDumps
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
    ) {
    }

    /**
     * @throws ArkhiveException     a dump or connection failed (dump directory emptied)
     * @throws RefusedException     not enough free space for the dumps (exit 73)
     * @throws InterruptedException SIGINT/SIGTERM (dump directory emptied by the cleanup registry)
     */
    public function run(Config $config, Logger $log): ?DumpRun
    {
        if (!$config->hasDumps() || $config->dumpDirectory === null) {
            return null;
        }
        $staging = new DumpStaging($config->dumpDirectory);
        $leftovers = $staging->prepare();
        if ($leftovers !== []) {
            $log->warning(sprintf('Removed %d dump file(s) left in %s by a run that did not finish', count($leftovers), $staging->directory));
        }
        $cleanupId = $this->cleanup->add('empty the dump directory', static function () use ($staging): void {
            $staging->clear();
        });

        /** @var list<array{string, MysqlDumper|PgsqlDumper, list<string>}> $plan */
        $plan = [];
        $results = [];
        try {
            if ($config->mysql !== null) {
                $mysql = new MysqlDumper($config->mysql, $this->runner, $this->dependencies, $this->cleanup);
                $plan[] = ['MySQL', $mysql, $mysql->databases()];
            }
            if ($config->pgsql !== null) {
                $pgsql = new PgsqlDumper($config->pgsql, $this->runner, $this->dependencies, $this->cleanup);
                $warning = $pgsql->versionWarning();
                if ($warning !== null) {
                    $log->warning($warning);
                }
                $plan[] = ['PostgreSQL', $pgsql, $pgsql->databases()];
            }
            $this->checkSpace($plan, $staging, $log);

            foreach ($plan as [$engine, $dumper, $databases]) {
                if ($databases === []) {
                    $log->warning("No {$engine} database to dump: the server has none besides the system schemas");
                }
                foreach ($databases as $database) {
                    $log->info("Dumping {$engine} database {$database}");
                    $result = $dumper->dump($database, $staging);
                    $results[] = $result;
                    $log->success(
                        sprintf('Dumped %s database %s: %s in %.1fs', $engine, $database, Bytes::format($result->bytes), $result->duration),
                        ['engine' => $result->engine, 'database' => $database, 'bytes' => $result->bytes],
                    );
                }
            }
        } catch (Throwable $e) {
            $this->cleanup->runOne($cleanupId);

            throw $e;
        } finally {
            foreach ($plan as [, $dumper]) {
                $dumper->close();
            }
        }

        return new DumpRun($staging, $results, $cleanupId);
    }

    /**
     * Removes this run's dumps (after restic backup, whether it succeeded or not).
     */
    public function clear(DumpRun $run): void
    {
        $this->cleanup->runOne($run->cleanupId);
    }

    /**
     * @param list<array{string, MysqlDumper|PgsqlDumper, list<string>}> $plan
     */
    private function checkSpace(array $plan, DumpStaging $staging, Logger $log): void
    {
        $needed = 0;
        foreach ($plan as [$engine, $dumper, $databases]) {
            try {
                $needed += $dumper->estimate($databases);
            } catch (InterruptedException $e) {
                throw $e;
            } catch (ArkhiveException $e) {
                $log->warning("Could not estimate the size of the {$engine} dumps, skipping the free-space check: {$e->getMessage()}");

                return;
            }
        }
        $free = $staging->freeSpace();
        if ($free === null || $needed <= $free) {
            return;
        }

        throw new RefusedException(
            sprintf('Not enough free space for the database dumps in %s: about %s needed, %s available', $staging->directory, Bytes::format($needed), Bytes::format($free)),
            'Free some space, or point DUMP_DIRECTORY to a larger filesystem',
        );
    }
}
