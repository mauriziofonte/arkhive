<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\ProcessTable;
use Mfonte\Arkhive\Application\Port\Prompter;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Application\Restore\RestorePlanner;
use Mfonte\Arkhive\Application\Restore\RestoreRunner;
use Mfonte\Arkhive\Application\Restore\TargetDirectory;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Restore\RestoreOptions;
use Mfonte\Arkhive\Domain\Restore\RestorePlan;
use Mfonte\Arkhive\Domain\System\ProcessState;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * spec §10: the guided restore in a terminal, the same choices as flags for scripts, and bare-metal
 * recovery with --repo/--password-file on a host without configuration.
 */
final class RestoreCommand extends RepositoryCommand
{
    protected $signature = 'restore
        {destination? : (1.x) use --target}
        {--snapshot= : Snapshot id, or "latest" (required without a terminal)}
        {--host= : Only snapshots of this host (for "latest" and the wizard)}
        {--include=* : Restore only this path (repeatable); none = everything}
        {--exclude=* : Leave out paths matching this pattern (repeatable)}
        {--target= : New or empty directory to restore into (required without a terminal)}
        {--db= : Restore this database dump only (name, or mysql/name, pgsql/name)}
        {--import-as= : With --db: import the dump into this NEW database}
        {--to-stdout= : Write this single file to standard output}
        {--in-place : Restore over the original paths (needs --force; a safety snapshot is taken first)}
        {--force : Restore into a non-empty target, in place, or import into an existing database}
        {--owner= : preserve (default as root) or current (default otherwise)}
        {--yes : Answer the final confirmation (required without a terminal)}
        {--json : Print the result as JSON on stdout}';

    protected $description = 'Restore files, a database dump or a single file from a snapshot (guided in a terminal)';

    protected function perform(): int
    {
        $legacy = self::stringOption($this->argument('destination'));
        if ($legacy !== null) {
            throw new UsageException("The 1.x destination argument is now --target: arkhive restore --target {$legacy}", 'In a terminal, arkhive restore alone starts the guided restore');
        }
        $options = $this->restoreOptions();
        $options->validate();
        if ((bool) $this->option('json') && $options->toStdout !== null) {
            throw new UsageException('--json cannot be combined with --to-stdout: standard output carries the file');
        }

        [$settings, $config] = $this->repositorySettings();
        $restic = $this->openRepository($settings, 'to restore');
        $this->reportLocks($restic, $settings->repository);

        $snapshots = new Snapshots($restic->snapshots());
        $prompter = $this->context->interactive ? $this->laravel->make(Prompter::class) : null;
        $planner = new RestorePlanner($restic, $prompter, $this->clock, getcwd() ?: '/', self::hostname(), posix_geteuid(), $config);
        $plan = $planner->plan($options, $snapshots);

        $runner = new RestoreRunner(
            $this->laravel->make(ProcessRunner::class),
            $this->laravel->make(Dependencies::class),
            $this->cleanup,
            $this->clock,
            $this->terminal->environment(),
            posix_geteuid(),
            posix_getegid(),
            $this->laravel->make(Retrier::class),
        );
        $needed = $runner->preflight($restic, $plan, $config);
        $planner->confirm($plan, $needed, $this->freeSpace($plan), $options->yes);

        $host = $config?->restic->host ?? self::hostname();
        $report = $this->withStdout(fn ($stdout) => $runner->run($restic, $plan, $config, $host, $this->log, $stdout));

        foreach ($report->nextSteps as $step) {
            $this->log->notice($step);
        }
        if ((bool) $this->option('json')) {
            $this->result(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $report->warnings === [] ? ExitCode::SUCCESS : ExitCode::WARNING;
    }

    private function restoreOptions(): RestoreOptions
    {
        return new RestoreOptions(
            snapshot: self::stringOption($this->option('snapshot')),
            host: $this->hostOption(),
            includes: self::listOption($this->option('include')),
            excludes: self::listOption($this->option('exclude')),
            target: self::stringOption($this->option('target')),
            db: self::stringOption($this->option('db')),
            importAs: self::stringOption($this->option('import-as')),
            toStdout: self::stringOption($this->option('to-stdout')),
            inPlace: (bool) $this->option('in-place'),
            force: (bool) $this->option('force'),
            owner: self::stringOption($this->option('owner')),
            yes: (bool) $this->option('yes'),
        );
    }

    /**
     * Wizard step 1: locks in the repository. A stale one is explained with the exact unlock command;
     * ArkHive never removes a lock itself.
     */
    private function reportLocks(SnapshotStore $restic, string $repository): void
    {
        try {
            $locks = $restic->locks();
        } catch (ArkhiveException $e) {
            $this->log->debug('Could not list the repository locks: ' . $e->getMessage());

            return;
        }
        $now = $this->clock->now();
        $processes = $this->laravel->make(ProcessTable::class);
        foreach ($locks as $lock) {
            $stale = $lock->isStale($now, self::hostname(), static fn (int $pid): bool => $processes->state($pid) !== ProcessState::Gone);
            if ($stale) {
                $this->log->warning('The repository has a stale lock: ' . $lock->describe() . '; it was left by a restic run that was killed or lost its connection');
                $this->log->notice('If no other restic process uses the repository, remove it with: RESTIC_PASSWORD_FILE=' . escapeshellarg($restic->settings()->passwordFile) . ' restic -r ' . escapeshellarg($repository) . ' unlock (ArkHive never does this automatically)');
            } elseif ($lock->exclusive) {
                $this->log->warning('The repository is locked exclusively (' . $lock->describe() . '), probably by a running prune: the restore fails until it ends');
            } else {
                $this->log->debug('Repository in use: ' . $lock->describe());
            }
        }
    }

    private function freeSpace(RestorePlan $plan): ?int
    {
        return $plan->target === null ? null : (new TargetDirectory($plan->target))->freeSpace();
    }

    /**
     * Runs $task with the stream where --to-stdout writes: the real stdout, or a buffer copied to
     * the command output when that is not a stream (in-process tests).
     *
     * @template T
     *
     * @param callable(resource): T $task
     *
     * @return T
     */
    private function withStdout(callable $task): mixed
    {
        $output = $this->output->getOutput();
        if ($output instanceof StreamOutput) {
            return $task($output->getStream());
        }
        $buffer = fopen('php://temp', 'w+b');
        if ($buffer === false) {
            throw new SoftwareException('Cannot open a buffer for standard output');
        }
        try {
            $result = $task($buffer);
            rewind($buffer);
            $this->output->write((string) stream_get_contents($buffer), false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

            return $result;
        } finally {
            fclose($buffer);
        }
    }

    /**
     * @return list<string>
     */
    private static function listOption(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }
}
