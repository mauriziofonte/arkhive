<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Run;

use Mfonte\Arkhive\ExitCode;

/**
 * What the recorded runs say about the repository. A backup cannot tell that the repository lost data
 * (restic trusts its index and deduplicates against blobs that may be gone): only `maintain` (check)
 * and `drill` (restore and verify) can, so their last verdict is carried over to the next backups.
 */
final class RepositoryHealth
{
    /**
     * The last maintain or drill that found the repository damaged (exit 65), unless a maintain
     * succeeded after it.
     *
     * @param array<string, JobState> $states by job
     */
    public static function unresolvedDataError(array $states): ?RunRecord
    {
        $damage = null;
        foreach (['maintain', 'drill'] as $job) {
            $last = $states[$job]->last ?? null;
            if ($last !== null && $last->exitCode === ExitCode::DATA_ERROR && ($damage === null || $last->ended > $damage->ended)) {
                $damage = $last;
            }
        }
        if ($damage === null) {
            return null;
        }
        $repaired = $states['maintain']->lastSuccess ?? null;

        return $repaired !== null && $repaired->started > $damage->ended ? null : $damage;
    }

    /**
     * The warning a backup ends with while the repository is known to be damaged.
     */
    public static function warning(RunRecord $damage, \DateTimeZone $zone): string
    {
        return sprintf(
            'the last %s (%s) found the repository damaged and no maintain has passed since: this snapshot may use data the repository no longer has; repair it (arkhive maintain names the steps)',
            $damage->job,
            $damage->ended->setTimezone($zone)->format('Y-m-d H:i T'),
        );
    }
}
