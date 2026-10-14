<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Legacy;

/**
 * What decrypt-legacy extracted.
 */
final readonly class LegacyReport
{
    public function __construct(
        public string $archive,
        public string $target,
        public bool $encrypted,
        public string $compression,
        public int $entries,
        public int $bytes,
    ) {
    }

    /**
     * @return array{archive: string, target: string, encrypted: bool, compression: string, entries: int, bytes: int}
     */
    public function toArray(): array
    {
        return [
            'archive' => $this->archive,
            'target' => $this->target,
            'encrypted' => $this->encrypted,
            'compression' => $this->compression,
            'entries' => $this->entries,
            'bytes' => $this->bytes,
        ];
    }
}
