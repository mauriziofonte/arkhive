<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Backup;

/**
 * A database dump that failed while the backup went on: one database, or every database of an
 * engine when the server could not even be asked for its list.
 */
final readonly class DumpFailure
{
    public function __construct(
        public string $engine,
        public ?string $database,
        public string $error,
        public int $exitCode,
        public ?string $hint = null,
    ) {
    }

    public function describe(): string
    {
        return $this->database === null ? "the {$this->engine} dumps" : "the {$this->engine} dump of {$this->database}";
    }

    /**
     * @return array{engine: string, database: string|null, error: string, exit_code: int}
     */
    public function toArray(): array
    {
        return ['engine' => $this->engine, 'database' => $this->database, 'error' => $this->error, 'exit_code' => $this->exitCode];
    }
}
