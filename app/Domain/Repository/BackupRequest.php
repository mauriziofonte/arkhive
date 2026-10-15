<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * One snapshot to take: what to save, how to label it, and how to report it.
 */
final readonly class BackupRequest
{
    /**
     * @param list<string> $paths           absolute paths to save
     * @param list<string> $tags
     * @param string       $what            names the run in an error message
     * @param bool         $allowIncomplete a snapshot saved with some unreadable source items is a result, not an error
     * @param bool         $showProgress    ask for frequent progress reports (interactive runs)
     */
    public function __construct(
        public array $paths,
        public string $host,
        public array $tags,
        public string $what,
        public ?string $compression = null,
        public ?string $excludeFile = null,
        public bool $allowIncomplete = false,
        public bool $showProgress = false,
    ) {
    }
}
