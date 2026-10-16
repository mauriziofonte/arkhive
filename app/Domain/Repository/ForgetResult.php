<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * What `restic forget --json` decided, summed over its snapshot groups.
 */
final readonly class ForgetResult
{
    /**
     * @param list<string> $kept    snapshot ids
     * @param list<string> $removed snapshot ids
     */
    public function __construct(public array $kept, public array $removed)
    {
    }
}
