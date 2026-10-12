<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Restic\Snapshot;

/**
 * Picks the snapshot a restore means. "latest" is the newest backup: safety snapshots (tag
 * pre-restore) are only chosen by id, and with several hosts in the repository the host must be given.
 */
final class Snapshots
{
    public const SAFETY_TAG = 'pre-restore';

    /**
     * @param list<Snapshot> $snapshots newest first
     */
    public function __construct(public readonly array $snapshots)
    {
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = array_values(array_unique(array_map(static fn (Snapshot $s): string => $s->hostname, $this->snapshots)));
        sort($hosts);

        return $hosts;
    }

    public function forHost(?string $host): self
    {
        if ($host === null) {
            return $this;
        }

        return new self(array_values(array_filter($this->snapshots, static fn (Snapshot $s): bool => $s->hostname === $host)));
    }

    /**
     * Backups only (no safety snapshots).
     */
    public function backups(): self
    {
        return new self(array_values(array_filter($this->snapshots, static fn (Snapshot $s): bool => !$s->hasTag(self::SAFETY_TAG))));
    }

    public function isEmpty(): bool
    {
        return $this->snapshots === [];
    }

    /**
     * @param string|null $host  restrict "latest" (and ids) to this host
     * @param string|null $local this machine's host name, preferred for "latest" when the repository holds several hosts
     */
    public function resolve(string $reference, ?string $host = null, ?string $local = null): Snapshot
    {
        $reference = trim($reference);
        if ($this->snapshots === []) {
            throw new ArkhiveException('The repository has no snapshot yet', hint: 'Run a backup first: arkhive backup');
        }
        if (strtolower($reference) === 'latest') {
            return $this->latest($host, $local);
        }
        if (preg_match('/^[0-9a-f]{4,64}$/i', $reference) !== 1) {
            throw new UsageException("--snapshot must be a snapshot id or 'latest', not '{$reference}'", 'List the snapshots with: arkhive restore (in a terminal) or arkhive ls');
        }
        $matches = array_values(array_filter(
            $this->forHost($host)->snapshots,
            static fn (Snapshot $s): bool => str_starts_with($s->id, strtolower($reference)),
        ));
        if ($matches === []) {
            throw new UsageException("There is no snapshot {$reference}" . ($host === null ? '' : " of host {$host}") . ' in the repository');
        }
        if (count($matches) > 1) {
            throw new UsageException("Snapshot id {$reference} is ambiguous: it matches " . implode(', ', array_map(static fn (Snapshot $s): string => $s->shortId(), $matches)), 'Give more characters of the id');
        }

        return $matches[0];
    }

    private function latest(?string $host, ?string $local): Snapshot
    {
        $backups = $this->backups();
        if ($host === null) {
            $hosts = $backups->hosts();
            if (count($hosts) > 1) {
                if ($local === null || !in_array($local, $hosts, true)) {
                    throw new UsageException(
                        'The repository holds snapshots of several hosts (' . implode(', ', $hosts) . "): 'latest' needs --host",
                        'Pass --host with the host whose data you want',
                    );
                }
                $host = $local;
            }
        }
        $candidates = $backups->forHost($host)->snapshots;
        if ($candidates === []) {
            throw new UsageException($host === null ? 'The repository has only safety snapshots (pre-restore): give the snapshot id' : "There is no snapshot of host {$host} in the repository");
        }

        return $candidates[0];
    }
}
