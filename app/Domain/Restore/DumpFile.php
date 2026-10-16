<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Restore;

/**
 * A database dump inside a snapshot: <dump directory>/<engine>/<percent-encoded name>.sql.
 */
final readonly class DumpFile
{
    public function __construct(
        public string $engine,
        public string $database,
        public string $path,
        public int $size,
    ) {
    }

    public function engineLabel(): string
    {
        return $this->engine === 'mysql' ? 'MySQL/MariaDB' : 'PostgreSQL';
    }

    public function label(): string
    {
        return "{$this->engine}/{$this->database}";
    }
}
