<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use FilesystemIterator;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\System\Dependency;
use Mfonte\Arkhive\Services\System\InstallHints;

/**
 * spec §10.3: a read-only FUSE mount of the repository (`restic mount`), only where FUSE works;
 * otherwise a clear pointer to ls and restore --include. Runs until Ctrl-C (or SIGTERM).
 */
final class MountCommand extends RepositoryCommand
{
    protected $signature = 'mount
        {directory : Empty directory to mount the repository on}
        {--host= : Only snapshots of this host}';

    protected $description = 'Mount the repository read-only with FUSE to browse every snapshot';

    /** where the FUSE device is expected; a property so that tests can point elsewhere */
    public static string $fuseDevice = '/dev/fuse';

    protected function perform(): int
    {
        $directory = self::stringOption($this->argument('directory')) ?? throw new UsageException('The mount point is required');
        $dependencies = $this->laravel->make(Dependencies::class);
        $fusermount = $dependencies->find(Dependency::Fuse);
        if (!file_exists(self::$fuseDevice) || $fusermount === null) {
            $missing = !file_exists(self::$fuseDevice) ? self::$fuseDevice . ' does not exist' : 'fusermount is not installed';
            throw new ArkhiveException(
                "FUSE is not available on this host ({$missing}), so the repository cannot be mounted",
                hint: 'Browse without FUSE: arkhive ls latest /path, arkhive find <name>, then arkhive restore --include /path --target <new directory>'
                    . (file_exists(self::$fuseDevice) ? '. To enable mounting: ' . InstallHints::forDependency(Dependency::Fuse, $dependencies->distro()) : ' (in a container FUSE needs --device /dev/fuse)'),
            );
        }
        if (!is_dir($directory)) {
            throw new UsageException("The mount point {$directory} is not a directory", "Create it first: mkdir {$directory}");
        }
        if ((new FilesystemIterator($directory))->valid()) {
            throw new UsageException("The mount point {$directory} is not empty");
        }

        [$settings] = $this->repositorySettings();
        $restic = $this->openRepository($settings, 'to mount the repository');
        $args = ['mount'];
        if ($this->hostOption() !== null) {
            array_push($args, '--host', $this->hostOption());
        }
        array_push($args, '--', $directory);
        $this->log->info("Mounting the repository read-only on {$directory}: snapshots are under {$directory}/snapshots, ids/, hosts/ and tags/. Stop with Ctrl-C");
        try {
            $restic->run($args, 'restic mount');
        } catch (InterruptedException) {
            $this->log->success("Unmounted {$directory}");

            return ExitCode::SUCCESS;
        }
        $this->log->success("{$directory} was unmounted");

        return ExitCode::SUCCESS;
    }
}
