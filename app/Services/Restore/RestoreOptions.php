<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * The restore flags (spec §10.2), checked for combinations that cannot work together.
 */
final readonly class RestoreOptions
{
    /**
     * @param list<string> $includes
     * @param list<string> $excludes
     */
    public function __construct(
        public ?string $snapshot = null,
        public ?string $host = null,
        public array $includes = [],
        public array $excludes = [],
        public ?string $target = null,
        public ?string $db = null,
        public ?string $importAs = null,
        public ?string $toStdout = null,
        public bool $inPlace = false,
        public bool $force = false,
        public ?string $owner = null,
        public bool $yes = false,
    ) {
    }

    public function validate(): void
    {
        $selections = array_filter([
            '--include/--exclude' => $this->includes !== [] || $this->excludes !== [],
            '--db' => $this->db !== null,
            '--to-stdout' => $this->toStdout !== null,
        ]);
        if (count($selections) > 1) {
            throw new UsageException(implode(' and ', array_keys($selections)) . ' cannot be combined: choose one kind of selection');
        }
        if ($this->importAs !== null && $this->db === null) {
            throw new UsageException('--import-as needs --db: it names the new database for that dump');
        }
        if ($this->inPlace) {
            foreach (['--target' => $this->target, '--db' => $this->db, '--to-stdout' => $this->toStdout] as $flag => $value) {
                if ($value !== null) {
                    throw new UsageException("--in-place cannot be combined with {$flag}: an in-place restore writes to the original paths");
                }
            }
        }
        if ($this->target !== null && ($this->toStdout !== null || $this->importAs !== null)) {
            throw new UsageException('--target cannot be combined with ' . ($this->toStdout !== null ? '--to-stdout' : '--import-as') . ': nothing is written to a directory');
        }
    }

    /**
     * Whether any choice of the wizard's "what" step was given as a flag.
     */
    public function hasSelection(): bool
    {
        return $this->includes !== [] || $this->excludes !== [] || $this->db !== null || $this->toStdout !== null || $this->inPlace;
    }
}
