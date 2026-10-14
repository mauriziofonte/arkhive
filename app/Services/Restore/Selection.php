<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Support\Bytes;

/**
 * What to restore from the snapshot.
 */
final readonly class Selection
{
    /**
     * @param list<string> $includes restic include patterns (absolute paths in practice)
     * @param list<string> $excludes restic exclude patterns
     */
    private function __construct(
        public SelectionKind $kind,
        public array $includes = [],
        public array $excludes = [],
        public ?DumpFile $dump = null,
        public ?string $importAs = null,
        public ?string $file = null,
    ) {
    }

    /**
     * @param list<string> $excludes
     */
    public static function everything(array $excludes = []): self
    {
        return new self(SelectionKind::Everything, [], $excludes);
    }

    /**
     * @param list<string> $includes
     * @param list<string> $excludes
     */
    public static function paths(array $includes, array $excludes = []): self
    {
        return $includes === [] ? self::everything($excludes) : new self(SelectionKind::Paths, $includes, $excludes);
    }

    public static function database(DumpFile $dump, ?string $importAs = null): self
    {
        return new self(SelectionKind::Database, [$dump->path], [], $dump, $importAs);
    }

    public static function stdout(string $file): self
    {
        return new self(SelectionKind::Stdout, [$file], [], null, null, $file);
    }

    public function imports(): bool
    {
        return $this->kind === SelectionKind::Database && $this->importAs !== null;
    }

    /**
     * Whether the selection writes into a target directory.
     */
    public function needsTarget(): bool
    {
        return $this->kind !== SelectionKind::Stdout && !$this->imports();
    }

    public function describe(): string
    {
        $excludes = $this->excludes === [] ? '' : ', except ' . implode(', ', $this->excludes);

        return match ($this->kind) {
            SelectionKind::Everything => 'everything' . $excludes,
            SelectionKind::Paths => implode(', ', $this->includes) . $excludes,
            SelectionKind::Database => $this->dump === null ? 'a database dump' : sprintf(
                '%s database %s (%s)%s',
                $this->dump->engineLabel(),
                $this->dump->database,
                Bytes::format($this->dump->size),
                $this->importAs === null ? '' : ", imported into the new database {$this->importAs}",
            ),
            SelectionKind::Stdout => "{$this->file} to standard output",
        };
    }
}
