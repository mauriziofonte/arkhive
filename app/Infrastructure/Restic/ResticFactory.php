<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\System\Dependency;

/**
 * Finds the restic binary (RESTIC_BINARY, or restic in PATH) and builds the client; $cacheDirectory
 * is the RESTIC_CACHE_DIR to give it (null: restic's own choice).
 */
final class ResticFactory implements SnapshotStoreFactory
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly ?string $cacheDirectory = null,
    ) {
    }

    public function create(ResticSettings $settings, string $purpose): Restic
    {
        $binary = $settings->binary === 'restic'
            ? $this->dependencies->require(Dependency::Restic, $purpose)
            : $this->dependencies->requireConfigured($settings->binary, Dependency::Restic, $purpose, 'RESTIC_BINARY');

        return new Restic($settings, $binary, $this->runner, $this->cacheDirectory);
    }
}
