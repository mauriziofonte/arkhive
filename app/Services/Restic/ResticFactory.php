<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

use Mfonte\Arkhive\Services\Config\ResticSettings;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\System\Dependency;

/**
 * Finds the restic binary (RESTIC_BINARY, or restic in PATH) and builds the client.
 */
final class ResticFactory
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
    ) {
    }

    public function create(ResticSettings $settings, string $purpose): Restic
    {
        $binary = $settings->binary === 'restic'
            ? $this->dependencies->require(Dependency::Restic, $purpose)
            : $this->dependencies->requireConfigured($settings->binary, Dependency::Restic, $purpose, 'RESTIC_BINARY');

        return new Restic($settings, $binary, $this->runner);
    }
}
