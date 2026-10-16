<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\State;

use Mfonte\Arkhive\Application\Port\RunStateStore;
use Mfonte\Arkhive\Domain\Repository\RepositoryKey;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\PermissionException;

/**
 * One JSON file per job in the state directory (0700): `backup.json`, ... for the last run on this
 * host whatever the repository, and `<key>/backup.json`, ... for each repository (the RepositoryKey of
 * its URL, as for the run lock). Files are replaced atomically: written to a temporary file in the
 * same directory, synced, then renamed over the old one.
 *
 * 2.1.0-rc.1 kept only the host files. The first read of a repository's state takes over the host
 * records that name no repository, and writes them to the repository's files.
 */
final class JsonRunStateStore implements RunStateStore
{
    public function __construct(private readonly ?string $directory)
    {
    }

    public function location(): ?string
    {
        return $this->directory;
    }

    public function load(string $job, ?string $repository = null): JobState
    {
        $file = $this->file($job, $repository);
        if ($repository === null || $file === null || is_file($file)) {
            return self::read($file);
        }
        $taken = self::read($this->file($job))->forRepository($repository);
        if (!$taken->isEmpty()) {
            try {
                $this->save($job, $taken, $repository);
            } catch (ArkhiveException) {
                // a state directory this user cannot write: read the host records again next time
            }
        }

        return $taken;
    }

    public function save(string $job, JobState $state, ?string $repository = null): void
    {
        $file = $this->file($job, $repository);
        if ($file === null || $this->directory === null) {
            throw new IoException('There is no state directory');
        }
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PermissionException("Cannot create the state directory {$directory}");
        }
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(4));
        $umask = umask(0077);
        try {
            $handle = @fopen($temporary, 'x');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new PermissionException("Cannot write in the state directory {$directory}");
        }
        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        $written = @fwrite($handle, $json);
        $synced = @fflush($handle) && @fsync($handle);
        fclose($handle);
        if ($written !== strlen($json) || !$synced || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new IoException("Cannot write {$file}");
        }
    }

    private static function read(?string $file): JobState
    {
        if ($file === null || !is_file($file)) {
            return new JobState();
        }
        $json = @file_get_contents($file);
        if ($json === false) {
            return new JobState();
        }
        try {
            return JobState::fromArray(json_decode($json, true, 16, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return new JobState();
        }
    }

    private function file(string $job, ?string $repository = null): ?string
    {
        if ($this->directory === null || preg_match('/^[a-z]+$/', $job) !== 1 || ($repository !== null && !RepositoryKey::valid($repository))) {
            return null;
        }

        return $repository === null ? "{$this->directory}/{$job}.json" : "{$this->directory}/{$repository}/{$job}.json";
    }
}
