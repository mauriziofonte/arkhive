<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\DiskSpace;
use Mfonte\Arkhive\Domain\Backup\DumpFailure;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Throwable;

/**
 * Runs every configured dump into DUMP_DIRECTORY before restic backup. A database (or a server) that
 * fails is recorded and the other dumps go on, so one broken database never costs the backup of the
 * rest; a failed dump leaves no partial file. Running out of space (73, 74) aborts every dump.
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
     * @throws RefusedException     not enough free space for the dumps (exit 73)
     * @throws IoException          the dump filesystem filled up (exit 74)
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
        $dumpers = [];
        $results = [];
        $failures = [];
        try {
            if ($config->mysql !== null) {
                $mysql = $dumpers[] = new MysqlDumper($config->mysql, $this->runner, $this->dependencies, $this->cleanup);
                $databases = $this->attempt('MySQL', null, $mysql->databases(...), $failures, $log);
                if ($databases !== null) {
                    $plan[] = ['MySQL', $mysql, $databases];
                }
            }
            if ($config->pgsql !== null) {
                $pgsql = $dumpers[] = new PgsqlDumper($config->pgsql, $this->runner, $this->dependencies, $this->cleanup);
                $databases = $this->attempt('PostgreSQL', null, static function () use ($pgsql, $log): array {
                    $warning = $pgsql->versionWarning();
                    if ($warning !== null) {
                        $log->warning($warning);
                    }

                    return $pgsql->databases();
                }, $failures, $log);
                if ($databases !== null) {
                    $plan[] = ['PostgreSQL', $pgsql, $databases];
                }
            }
            $this->checkSpace($plan, $staging, $log);

            foreach ($plan as [$engine, $dumper, $databases]) {
                if ($databases === []) {
                    $log->warning("No {$engine} database to dump: the server has none besides the system schemas");
                }
                foreach ($databases as $database) {
                    $log->info("Dumping {$engine} database {$database}");
                    $result = $this->attempt($engine, $database, static fn () => DiskSpace::whenFull(static fn () => $dumper->dump($database, $staging), $staging->directory), $failures, $log);
                    if ($result === null) {
                        continue;
                    }
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
            foreach ($dumpers as $dumper) {
                $dumper->close();
            }
        }

        return new DumpRun($staging, $results, $cleanupId, $failures);
    }

    /**
     * Runs one step of the dumps; a failure that concerns only this database or server is recorded
     * (and logged) instead of thrown. Interruptions and a full filesystem still stop everything.
     *
     * @template T
     *
     * @param \Closure(): T     $step
     * @param list<DumpFailure> $failures
     *
     * @return T|null null when the step failed
     */
    private function attempt(string $engine, ?string $database, \Closure $step, array &$failures, Logger $log): mixed
    {
        try {
            return $step();
        } catch (InterruptedException | IoException | RefusedException $e) {
            throw $e;
        } catch (ArkhiveException $e) {
            $failure = new DumpFailure($engine, $database, $e->getMessage(), $e->exitCode(), $e->hint());
            $failures[] = $failure;
            $log->warning(ucfirst($failure->describe()) . ' failed, the backup goes on without it: ' . $e->getMessage(), ['engine' => $engine, 'database' => $database, 'exit_code' => $e->exitCode()]);
            if ($e->hint() !== null) {
                $log->notice($e->hint());
            }

            return null;
        }
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
