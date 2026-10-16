<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Schedule;

use Mfonte\Arkhive\Application\Schedule\Invocation;
use Mfonte\Arkhive\Application\System\BinaryFinder;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class InvocationTest extends TestCase
{
    use TempDir;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/std");
        file_put_contents("{$this->dir}/arkhive.phar", "#!/usr/bin/env php\n");
    }

    public function testFromSourceThePhpBinaryRunsTheEntryPoint(): void
    {
        self::assertSame(['/usr/bin/php8.4', '/srv/arkhive/arkhive'], Invocation::resolve('', '/srv/arkhive/arkhive', '/usr/bin/php8.4', $this->standard(withPhp: true)));
    }

    public function testAnExecutablePharRunsByItselfWhenPhpIsInTheStandardDirectories(): void
    {
        chmod("{$this->dir}/arkhive.phar", 0755);

        self::assertSame(["{$this->dir}/arkhive.phar"], Invocation::resolve("{$this->dir}/arkhive.phar", '/src', '/usr/bin/php8.4', $this->standard(withPhp: true)));
    }

    public function testWithoutPhpInTheStandardDirectoriesTheRunningPhpIsNamed(): void
    {
        chmod("{$this->dir}/arkhive.phar", 0755);

        // e.g. Alpine with only /usr/bin/php83: the shebang would not find php under cron or systemd
        self::assertSame(['/usr/bin/php83', "{$this->dir}/arkhive.phar"], Invocation::resolve("{$this->dir}/arkhive.phar", '/src', '/usr/bin/php83', $this->standard(withPhp: false)));
    }

    public function testANonExecutablePharIsRunByPhp(): void
    {
        chmod("{$this->dir}/arkhive.phar", 0644);

        self::assertSame(['/usr/bin/php8.4', "{$this->dir}/arkhive.phar"], Invocation::resolve("{$this->dir}/arkhive.phar", '/src', '/usr/bin/php8.4', $this->standard(withPhp: true)));
    }

    public function testTheStandardFinderIgnoresPath(): void
    {
        $previous = getenv('PATH');
        putenv("PATH={$this->dir}/std");
        try {
            file_put_contents("{$this->dir}/std/only-here", "#!/bin/sh\n");
            chmod("{$this->dir}/std/only-here", 0755);
            self::assertNull(Invocation::standardFinder()->find('only-here'));
            self::assertNotNull(Invocation::standardFinder()->find('sh'));
        } finally {
            putenv('PATH=' . (is_string($previous) ? $previous : ''));
        }
    }

    private function standard(bool $withPhp): BinaryFinder
    {
        if ($withPhp) {
            file_put_contents("{$this->dir}/std/php", "#!/bin/sh\n");
            chmod("{$this->dir}/std/php", 0755);
        }

        return new BinaryFinder('', ["{$this->dir}/std"]);
    }
}
