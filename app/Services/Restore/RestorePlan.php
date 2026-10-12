<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Services\Restic\Snapshot;

/**
 * Everything decided before restic runs, whether by the wizard or by flags.
 */
final readonly class RestorePlan
{
    /**
     * @param string|null $target absolute; "/" for an in-place restore; null for stdout and imports
     * @param bool        $force  restore into a non-empty target, or import into an existing database
     */
    public function __construct(
        public Snapshot $snapshot,
        public Selection $selection,
        public ?string $target,
        public OwnerMode $owner,
        public bool $inPlace = false,
        public bool $force = false,
    ) {
    }
}
