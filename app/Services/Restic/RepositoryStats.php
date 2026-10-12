<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        return new self(
            ResticJson::int($data, 'total_size'),
            array_key_exists('total_uncompressed_size', $data) ? ResticJson::int($data, 'total_uncompressed_size') : null,
            ResticJson::int($data, 'total_blob_count'),
            ResticJson::int($data, 'snapshots_count'),
        );
    }
}
