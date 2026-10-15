<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Output\TextTable;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;

/**
 * spec §10.3: the listing of a directory in a snapshot (mode, size, mtime).
 */
final class LsCommand extends RepositoryCommand
{
    protected $signature = 'ls
        {snapshot=latest : Snapshot id, or "latest"}
        {path? : Absolute directory (or file) in the snapshot; default: the backed-up paths}
        {--host= : Only snapshots of this host (for "latest")}
        {--recursive : Also list everything below the directory}
        {--json : One JSON object per entry on stdout}';

    protected $description = 'List a directory of a snapshot';

    protected function perform(): int
    {
        $path = self::stringOption($this->argument('path'));
        if ($path !== null && !str_starts_with($path, '/')) {
            throw new UsageException("The path must be absolute, as it was backed up: '{$path}'", 'For example: arkhive ls latest /srv/www');
        }
        [$settings] = $this->repositorySettings();
        $restic = $this->openRepository($settings, 'to list a snapshot');
        $snapshot = (new Snapshots($restic->snapshots()))->resolve(self::stringOption($this->argument('snapshot')) ?? 'latest', $this->hostOption(), self::hostname());
        $directories = $path === null ? $snapshot->paths : [rtrim($path, '/') === '' ? '/' : rtrim($path, '/')];

        $nodes = [];
        $restic->ls($snapshot->id, $directories, (bool) $this->option('recursive'), static function (Node $node) use (&$nodes): void {
            $nodes[] = $node;
        });
        if ($nodes === []) {
            throw new NoInputException("{$path} is not in snapshot {$snapshot->shortId()}", hint: 'Find it with: arkhive find ' . escapeshellarg(basename((string) $path)));
        }

        if ((bool) $this->option('json')) {
            foreach ($nodes as $node) {
                $this->result(json_encode($node->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            return ExitCode::SUCCESS;
        }

        $this->log->info(sprintf('Snapshot %s of %s, %s', $snapshot->shortId(), $snapshot->hostname, $snapshot->time->format('Y-m-d H:i:s T')));
        $rows = array_map(static fn (Node $node): array => [
            $node->permissions,
            $node->isDirectory() ? '-' : Bytes::format($node->size),
            $node->mtime->format('Y-m-d H:i'),
            $node->path . ($node->isDirectory() && $node->path !== '/' ? '/' : ''),
        ], $nodes);
        $this->result(rtrim(TextTable::render(['Mode', 'Size', 'Modified', 'Path'], $rows, [1])));

        return ExitCode::SUCCESS;
    }
}
