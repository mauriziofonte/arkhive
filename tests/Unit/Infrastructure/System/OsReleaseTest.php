<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\System;

use Mfonte\Arkhive\Infrastructure\System\OsRelease;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class OsReleaseTest extends TestCase
{
    use TempDir;

    public function testDetectReadsTheFirstReadableFileAndFallsBackToUnknown(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/os-release", "ID=alpine\nVERSION_ID=3.21.0\n");

        self::assertSame('alpine', OsRelease::detect(["{$dir}/missing", "{$dir}/os-release"])->id);
        $unknown = OsRelease::detect(["{$dir}/missing"]);
        self::assertSame('unknown', $unknown->id);
        self::assertSame('unknown', $unknown->describe());
        self::assertNull($unknown->packageManager());
    }
}
