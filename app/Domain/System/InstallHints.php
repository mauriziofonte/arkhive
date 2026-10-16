<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\System;

/**
 * The install command for a missing dependency on the detected distribution.
 */
final class InstallHints
{
    public static function forDependency(Dependency $dependency, Distro $distro): string
    {
        $manager = $distro->packageManager();
        if ($manager === null) {
            return "Install {$dependency->label()} with your distribution's package manager";
        }
        $command = $manager->install($dependency->packages($manager));
        if ($dependency === Dependency::Restic && $distro->isEnterpriseLinux()) {
            return "Run: dnf install epel-release, then: {$command}";
        }

        return "Run: {$command}";
    }

    /**
     * @param list<string> $extensions
     */
    public static function forPhpExtensions(array $extensions, Distro $distro, string $phpVersion = PHP_VERSION): string
    {
        $manager = $distro->packageManager();
        if ($manager === null) {
            return "Install the PHP extension(s) {$extensions[0]} with your distribution's package manager";
        }
        $packages = array_values(array_unique(array_map(
            static fn (string $extension): string => (string) arkhive_php_extension_package($extension, $distro->family(), $phpVersion),
            $extensions,
        )));

        return 'Run: ' . $manager->install($packages);
    }
}
