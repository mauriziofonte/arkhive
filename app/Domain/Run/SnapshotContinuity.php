<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Run;

use DateTimeZone;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Domain\Repository\Snapshots;

/**
 * Whether the snapshot of the last successful backup recorded on this host is still in the
 * repository. Snapshots deleted by someone with write access to the server, or a storage rolled
 * back to an older copy, leave a repository that restic finds healthy, with fewer snapshots: only
 * the record of what this host backed up last can tell.
 *
 * A missing snapshot is not a problem when a newer backup of the same host is there (retention may
 * have removed the recorded one after it). Records without a repository (2.1.0-rc.1) are not checked:
 * they may belong to another repository of this host.
 */
final readonly class SnapshotContinuity
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const SUPERSEDED = 'superseded';
    public const NOT_RECORDED = 'not_recorded';

    public const HINT = 'Find out who can delete snapshots on the repository server and whether its storage was restored from an older copy;'
        . ' then run arkhive maintain (restic check: a local cache may still list data the server no longer has) and arkhive backup.'
        . ' ArkHive cannot bring the snapshot back';

    private function __construct(
        public string $status,
        public ?RunRecord $recorded = null,
        public ?Snapshot $newest = null,
    ) {
    }

    /**
     * @param RunRecord|null $lastBackup    the last successful backup recorded for this repository
     * @param string         $repositoryKey RepositoryKey of the repository listed
     * @param Snapshots      $repository    every snapshot in the repository
     */
    public static function check(?RunRecord $lastBackup, string $repositoryKey, Snapshots $repository): self
    {
        $id = $lastBackup?->snapshotId;
        $host = $lastBackup?->host;
        if ($lastBackup === null || $id === null || $host === null || $lastBackup->repository !== $repositoryKey) {
            return new self(self::NOT_RECORDED, $lastBackup);
        }
        foreach ($repository->snapshots as $snapshot) {
            if ($snapshot->id === $id || (strlen($id) >= 8 && strlen($id) < 64 && str_starts_with($snapshot->id, $id))) {
                return new self(self::OK, $lastBackup, $repository->backups()->forHost($host)->snapshots[0] ?? $snapshot);
            }
        }
        $newest = $repository->backups()->forHost($host)->snapshots[0] ?? null;
        if ($newest !== null && $newest->time >= $lastBackup->started) {
            return new self(self::SUPERSEDED, $lastBackup, $newest);
        }

        return new self(self::MISSING, $lastBackup, $newest);
    }

    public function broken(): bool
    {
        return $this->status === self::MISSING;
    }

    public function message(DateTimeZone $zone): ?string
    {
        $recorded = $this->recorded;
        if (!$this->broken() || $recorded === null) {
            return null;
        }
        $text = sprintf(
            'snapshot %s recorded as the last successful backup at %s is missing from the repository (deleted or rolled back)',
            $recorded->snapshotId,
            $recorded->ended->setTimezone($zone)->format('Y-m-d H:i T'),
        );
        if ($this->newest === null) {
            return "{$text}; there is no snapshot of {$recorded->host} left in it";
        }

        return sprintf('%s; the newest snapshot of %s in it, %s of %s, is older', $text, $recorded->host, $this->newest->shortId(), $this->newest->time->setTimezone($zone)->format('Y-m-d H:i T'));
    }

    /**
     * @return array{status: string, recorded_snapshot: string|null, recorded_at: string|null, newest_snapshot: string|null, newest_time: string|null, newest_older: bool}
     */
    public function toArray(): array
    {
        $older = $this->newest !== null && $this->recorded !== null && $this->newest->time < $this->recorded->started;

        return [
            'status' => $this->status,
            'recorded_snapshot' => $this->recorded?->snapshotId,
            'recorded_at' => $this->recorded?->ended->format(DATE_ATOM),
            'newest_snapshot' => $this->newest?->id,
            'newest_time' => $this->newest?->time->format(DATE_ATOM),
            'newest_older' => $older,
        ];
    }
}
