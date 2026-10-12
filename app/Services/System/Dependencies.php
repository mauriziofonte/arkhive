<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\System;

use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * Locates dependencies and fails with the distro-specific install hint when one is missing.
 */
final class Dependencies
{
    public function __construct(
        private readonly BinaryFinder $finder,
        private readonly Distro $distro,
    ) {
    }

    public function find(Dependency $dependency): ?string
    {
        return $this->finder->first($dependency->binaries());
    }

    /**
     * @throws ArkhiveException
     */
    public function require(Dependency $dependency, string $purpose): string
    {
        $path = $this->find($dependency);
        if ($path !== null) {
            return $path;
        }

        throw new ArkhiveException(
            "{$dependency->label()} is needed {$purpose} but was not found in PATH or the standard directories",
            hint: InstallHints::forDependency($dependency, $this->distro),
        );
    }

    /**
     * An explicitly configured binary (e.g. RESTIC_BINARY): a path is used as given, a name is searched.
     *
     * @throws ArkhiveException
     */
    public function requireConfigured(string $binary, Dependency $dependency, string $purpose, string $key): string
    {
        $path = $this->finder->find($binary);
        if ($path !== null) {
            return $path;
        }
        $where = str_contains($binary, '/') ? "{$binary} is not an executable file" : "{$binary} was not found in PATH or the standard directories";

        throw new ArkhiveException(
            "{$dependency->label()} is needed {$purpose} but {$where} (from {$key})",
            hint: InstallHints::forDependency($dependency, $this->distro),
        );
    }

    public function distro(): Distro
    {
        return $this->distro;
    }
}
