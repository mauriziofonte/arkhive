<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Restore;

use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Domain\Exclusion\ResticPatternMatcher;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Domain\Restore\RestorePlan;
use Mfonte\Arkhive\Domain\Restore\SelectionKind;

/**
 * Bytes a restore will write: `restic stats --mode restore-size` for a whole snapshot, otherwise the
 * files of the snapshot that the selection keeps (restic's own include/exclude matching).
 */
final class SizeEstimator
{
    public static function estimate(SnapshotStore $restic, RestorePlan $plan): int
    {
        $selection = $plan->selection;
        if ($selection->dump !== null) {
            return $selection->dump->size;
        }
        if ($selection->kind === SelectionKind::Everything && $selection->excludes === []) {
            return $restic->restoreSize($plan->snapshot->id);
        }
        $total = 0;
        $restic->ls($plan->snapshot->id, [], true, static function (Node $node) use (&$total, $selection): void {
            if ($node->isFile() && self::selected($node->path, $selection->includes, $selection->excludes)) {
                $total += $node->size;
            }
        });

        return $total;
    }

    /**
     * @param list<string> $includes
     * @param list<string> $excludes
     */
    public static function selected(string $path, array $includes, array $excludes): bool
    {
        if (ResticPatternMatcher::excludes($excludes, $path)) {
            return false;
        }

        return $includes === [] || ResticPatternMatcher::excludes($includes, $path);
    }
}
