<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\State;

use Mfonte\Arkhive\Application\Port\RunStateStore;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\PermissionException;

/**
 * One JSON file per job (`backup.json`, ...) in the state directory (0700), replaced atomically:
 * written to a temporary file in the same directory, synced, then renamed over the old one.
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

    public function load(string $job): JobState
    {
        $file = $this->file($job);
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

    public function save(string $job, JobState $state): void
    {
        $file = $this->file($job);
        if ($file === null || $this->directory === null) {
            throw new IoException('There is no state directory');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new PermissionException("Cannot create the state directory {$this->directory}");
        }
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(4));
        $umask = umask(0077);
        try {
            $handle = @fopen($temporary, 'x');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new PermissionException("Cannot write in the state directory {$this->directory}");
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

    private function file(string $job): ?string
    {
        if ($this->directory === null || preg_match('/^[a-z]+$/', $job) !== 1) {
            return null;
        }

        return "{$this->directory}/{$job}.json";
    }
}
