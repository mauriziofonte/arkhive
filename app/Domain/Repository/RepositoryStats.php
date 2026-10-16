<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * `restic stats --mode raw-data`: what the repository stores, after deduplication and compression.
 */
final readonly class RepositoryStats
{
    public function __construct(
        public int $rawBytes,
        public ?int $uncompressedBytes,
        public int $blobs,
        public int $snapshots,
    ) {
    }
}
