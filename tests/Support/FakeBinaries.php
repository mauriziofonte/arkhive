<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\System\BinaryFinder;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\System\Distro;

/**
 * Executable placeholders in a directory, so that dependency lookup succeeds without the real tools.
 */
final class FakeBinaries
{
    /**
     * @param list<string> $names
     */
    public static function dependencies(string $dir, array $names, string $osRelease = "ID=debian\nVERSION_ID=12\n"): Dependencies
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach ($names as $name) {
            file_put_contents("{$dir}/{$name}", "#!/bin/sh\nexit 0\n");
            chmod("{$dir}/{$name}", 0755);
        }

        return new Dependencies(new BinaryFinder($dir, []), Distro::fromOsRelease($osRelease));
    }
}
