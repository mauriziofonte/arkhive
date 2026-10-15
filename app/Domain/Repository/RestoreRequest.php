<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * Files of one snapshot to write into a target directory, each verified after writing.
 */
final readonly class RestoreRequest
{
    /**
     * @param list<string> $includes restic include patterns (all files when empty)
     * @param list<string> $excludes restic exclude patterns
     * @param string       $what     names the run in an error message
     */
    public function __construct(
        public string $snapshotId,
        public string $target,
        public string $what,
        public array $includes = [],
        public array $excludes = [],
        public bool $showProgress = false,
    ) {
    }
}
