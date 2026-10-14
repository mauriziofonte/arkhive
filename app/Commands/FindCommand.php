<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Restic\Node;
use Mfonte\Arkhive\Services\Restic\Snapshot;
use Mfonte\Arkhive\Support\Bytes;
use Mfonte\Arkhive\Support\TextTable;

/**
 * spec §10.3: which snapshots contain a path, and its versions, to answer "when did this file last
 * look right?". A version differs from the next older one when its size or modification time does.
 */
final class FindCommand extends RepositoryCommand
{
    protected $signature = 'find
        {pattern : File name or glob (e.g. "index.php", "*.conf"); a pattern with "/" matches whole paths}
        {--host= : Only snapshots of this host}
        {--json : The versions as JSON on stdout}';

    protected $description = 'Find which snapshots contain a file, and its versions';

    protected function perform(): int
    {
        $pattern = self::stringOption($this->argument('pattern')) ?? throw new UsageException('The pattern is required');
        [$settings] = $this->repositorySettings();
        $restic = $this->openRepository($settings, 'to search the snapshots');
        $snapshots = [];
        foreach ($restic->snapshots($this->hostOption()) as $snapshot) {
            $snapshots[$snapshot->id] = $snapshot;
        }

        /** @var array<string, list<array{Snapshot, Node}>> $versions */
        $versions = [];
        foreach ($restic->find($pattern, $this->hostOption()) as [$id, $node]) {
            if (isset($snapshots[$id])) {
                $versions[$node->path][] = [$snapshots[$id], $node];
            }
        }
        if ($versions === []) {
            throw new ArkhiveException("Nothing matching '{$pattern}' is in any snapshot" . ($this->hostOption() === null ? '' : " of host {$this->hostOption()}"), hint: 'Patterns match file names; use * as a wildcard, e.g. arkhive find ' . escapeshellarg("*{$pattern}*"));
        }
        ksort($versions);

        $json = [];
        foreach ($versions as $path => $list) {
            usort($list, static fn (array $a, array $b): int => [$b[0]->time, $b[0]->id] <=> [$a[0]->time, $a[0]->id]);
            $rows = [];
            foreach ($list as $i => [$snapshot, $node]) {
                $older = $list[$i + 1][1] ?? null;
                $changed = $older === null || $older->size !== $node->size || $older->mtime->format('U.u') !== $node->mtime->format('U.u');
                $rows[] = [
                    $snapshot->shortId(),
                    $snapshot->time->format('Y-m-d H:i'),
                    $snapshot->hostname,
                    $node->isDirectory() ? '-' : Bytes::format($node->size),
                    $node->mtime->format('Y-m-d H:i:s'),
                    $older === null ? 'oldest' : ($changed ? 'changed' : ''),
                ];
                $json[] = ['path' => $path, 'snapshot' => $snapshot->id, 'snapshot_time' => $snapshot->time->format(DATE_ATOM), 'host' => $snapshot->hostname, 'size' => $node->size, 'mtime' => $node->mtime->format(DATE_ATOM), 'type' => $node->type, 'changed' => $changed];
            }
            if (!(bool) $this->option('json')) {
                $this->result($path);
                $this->result(rtrim(TextTable::render(['  Snapshot', 'Date', 'Host', 'Size', 'Modified', ''], array_map(static fn (array $r): array => ['  ' . $r[0], ...array_slice($r, 1)], $rows), [3])));
            }
        }
        if ((bool) $this->option('json')) {
            $this->result(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->log->notice('Restore one version with: arkhive restore --snapshot <id> --include <path> --target <new directory>');
        }

        return ExitCode::SUCCESS;
    }
}
