<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Exceptions\ArkhiveException;

interface SnapshotStoreFactory
{
    /**
     * @param string $purpose why the client is needed, for the "not installed" error ("to back up")
     *
     * @throws ArkhiveException when the client program is missing
     */
    public function create(ResticSettings $settings, string $purpose): SnapshotStore;
}
