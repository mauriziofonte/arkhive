<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Update;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Services\Update\PharSwap;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * The running PHAR is replaced only at exit; any other file at once.
 */
final class PharSwapTest extends TestCase
{
    use TempDir;

    public function testDeferredOnlyForTheRunningPhar(): void
    {
        $dir = $this->tempDir();
        touch("{$dir}/arkhive");
        symlink("{$dir}/arkhive", "{$dir}/link");

        self::assertTrue(PharSwap::for("{$dir}/arkhive", "{$dir}/link")->deferred(), 'the same file through a symlink');
        self::assertFalse(PharSwap::for("{$dir}/arkhive", '')->deferred(), 'running from source');
        self::assertFalse(PharSwap::for("{$dir}/arkhive", "{$dir}/other.phar")->deferred());
    }

    public function testImmediateRename(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/new", 'new');
        file_put_contents("{$dir}/arkhive", 'old');

        (new PharSwap(false))->rename("{$dir}/new", "{$dir}/arkhive", true);

        self::assertSame('new', file_get_contents("{$dir}/arkhive"));
        self::assertFileDoesNotExist("{$dir}/new");
    }

    public function testAFailedRenameDiscardsOnlyADownload(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/download", 'new');
        file_put_contents("{$dir}/kept", 'old');
        $swap = new PharSwap(false);

        foreach (['download' => true, 'kept' => false] as $name => $discard) {
            try {
                $swap->rename("{$dir}/{$name}", "{$dir}/missing-dir/arkhive", $discard);
                self::fail('a rename into a missing directory worked');
            } catch (ArkhiveException $e) {
                self::assertSame("Cannot replace {$dir}/missing-dir/arkhive: the running version is unchanged", $e->getMessage());
            }
        }
        self::assertFileDoesNotExist("{$dir}/download");
        self::assertFileExists("{$dir}/kept");
    }

    public function testADeferredRenameWaitsForComplete(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/new", 'new');
        file_put_contents("{$dir}/arkhive", 'old');
        $swap = new PharSwap(true);

        $swap->rename("{$dir}/new", "{$dir}/arkhive", true);
        self::assertSame('old', file_get_contents("{$dir}/arkhive"), 'nothing changes before exit');

        $swap->complete();
        self::assertSame('new', file_get_contents("{$dir}/arkhive"));
        $swap->complete();
        self::assertSame('new', file_get_contents("{$dir}/arkhive"), 'done once');
    }
}
