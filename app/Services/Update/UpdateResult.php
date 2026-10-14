<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Update;

/**
 * What an update or a rollback changed.
 */
final readonly class UpdateResult
{
    public function __construct(
        public string $from,
        public string $to,
        public string $path,
        public string $backup,
        public string $provenance,
    ) {
    }

    /**
     * @return array{from: string, to: string, path: string, backup: string, provenance: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'path' => $this->path, 'backup' => $this->backup, 'provenance' => $this->provenance];
    }
}
