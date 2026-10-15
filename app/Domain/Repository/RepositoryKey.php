<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * A short, stable name for a repository URL, for file names: the run lock and the run state of a
 * repository. The URL itself never goes in a file name (it can hold a REST server's credentials).
 */
final class RepositoryKey
{
    public static function of(string $repository): string
    {
        return substr(hash('sha256', $repository), 0, 16);
    }

    public static function valid(string $key): bool
    {
        return preg_match('/^[0-9a-f]{16}$/', $key) === 1;
    }
}
