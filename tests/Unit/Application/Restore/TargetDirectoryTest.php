<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Restore;

use DateTimeImmutable;
use Mfonte\Arkhive\Application\Restore\TargetDirectory;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class TargetDirectoryTest extends TestCase
{
    use TempDir;

    public function testDefaultNameIsANewDirectoryNamedAfterTheSnapshot(): void
    {
        $now = new DateTimeImmutable('2026-10-12 14:30:05');

        self::assertSame('arkhive-restore-web1.example.com-6118b3c5-20261012-143005', TargetDirectory::defaultName('web1.example.com', '6118b3c5', $now));
        self::assertSame('arkhive-restore-my-host-6118b3c5-20261012-143005', TargetDirectory::defaultName('my host/', '6118b3c5', $now));
        self::assertSame('arkhive-restore-host-6118b3c5-20261012-143005', TargetDirectory::defaultName('../', '6118b3c5', $now));
    }

    public function testAbsolute(): void
    {
        self::assertSame('/home/op/restore', TargetDirectory::absolute('restore', '/home/op'));
        self::assertSame('/home/op/restore', TargetDirectory::absolute('./restore/', '/home/op/'));
        self::assertSame('/srv/restore', TargetDirectory::absolute('../../srv/./restore', '/home/op'));
        self::assertSame('/tmp/x', TargetDirectory::absolute('/tmp//x', '/home/op'));
        self::assertSame('/', TargetDirectory::absolute('/..', '/'));

        $this->expectException(UsageException::class);
        TargetDirectory::absolute('  ', '/home/op');
    }

    public function testNewAndEmptyTargetsAreUsable(): void
    {
        $dir = $this->tempDir();
        (new TargetDirectory("{$dir}/new/deeper"))->assertUsable(false);
        mkdir("{$dir}/empty");
        (new TargetDirectory("{$dir}/empty"))->assertUsable(false);

        self::assertTrue((new TargetDirectory("{$dir}/new"))->isEmpty());
        self::assertFalse((new TargetDirectory("{$dir}/new"))->exists());
        self::assertSame($dir, (new TargetDirectory("{$dir}/new/deeper"))->existingAncestor());
    }

    public function testANonEmptyTargetIsRefusedUnlessForced(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/full");
        touch("{$dir}/full/.hidden");
        $target = new TargetDirectory("{$dir}/full");

        try {
            $target->assertUsable(false);
            self::fail('expected a refusal');
        } catch (RefusedException $e) {
            self::assertSame(ExitCode::CANNOT_CREATE, $e->exitCode());
            self::assertSame("The restore target {$dir}/full is not empty: ArkHive restores only into a new or empty directory", $e->getMessage());
            self::assertStringContainsString('--force', (string) $e->hint());
        }
        $target->assertUsable(true);
    }

    public function testAFileOrASymlinkIsNeverATarget(): void
    {
        $dir = $this->tempDir();
        touch("{$dir}/file");
        mkdir("{$dir}/real");
        symlink("{$dir}/real", "{$dir}/link");

        foreach (["{$dir}/file", "{$dir}/link"] as $path) {
            try {
                (new TargetDirectory($path))->assertUsable(true);
                self::fail("{$path} should be refused");
            } catch (RefusedException $e) {
                self::assertStringContainsString('exists and is not a directory', $e->getMessage());
            }
        }
    }

    public function testSpaceCheckNamesBothNumbers(): void
    {
        $target = new TargetDirectory($this->tempDir() . '/new');
        $target->assertSpace(1);
        self::assertGreaterThan(0, $target->freeSpace());

        try {
            $target->assertSpace(PHP_INT_MAX);
            self::fail('expected a refusal');
        } catch (RefusedException $e) {
            self::assertMatchesRegularExpression('/^Not enough free space in \/.+ for the restore: 8192\.00 PiB needed, \d+\.\d\d [KMGT]iB available$/', $e->getMessage());
            self::assertSame(ExitCode::CANNOT_CREATE, $e->exitCode());
        }
    }

    public function testRemoveTreeDoesNotFollowSymlinks(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/outside");
        file_put_contents("{$dir}/outside/keep", 'precious');
        mkdir("{$dir}/target/a/b", 0755, true);
        file_put_contents("{$dir}/target/a/b/file", 'x');
        mkdir("{$dir}/target/ro", 0500);
        symlink("{$dir}/outside", "{$dir}/target/a/link");

        TargetDirectory::removeTree("{$dir}/target");

        self::assertFileDoesNotExist("{$dir}/target");
        self::assertSame('precious', file_get_contents("{$dir}/outside/keep"));
        TargetDirectory::removeTree("{$dir}/missing");
        touch("{$dir}/single");
        TargetDirectory::removeTree("{$dir}/single");
        self::assertFileDoesNotExist("{$dir}/single");
    }

    public function testUsedBytesCountsRegularFilesOnly(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/tree/sub", 0755, true);
        file_put_contents("{$dir}/tree/a", str_repeat('a', 100));
        file_put_contents("{$dir}/tree/sub/b", str_repeat('b', 50));
        symlink("{$dir}/tree/a", "{$dir}/tree/link");

        self::assertSame(150, TargetDirectory::usedBytes("{$dir}/tree"));
        self::assertSame(100, TargetDirectory::usedBytes("{$dir}/tree/a"));
        self::assertSame(0, TargetDirectory::usedBytes("{$dir}/tree/link"));
        self::assertSame(0, TargetDirectory::usedBytes("{$dir}/missing"));
    }

    public function testChownTree(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('changing owners needs root');
        }
        $dir = $this->tempDir();
        mkdir("{$dir}/tree/sub", 0755, true);
        touch("{$dir}/tree/sub/file");
        touch("{$dir}/outside");
        chown("{$dir}/outside", 4321);
        symlink("{$dir}/outside", "{$dir}/tree/link");
        foreach (["{$dir}/tree", "{$dir}/tree/sub", "{$dir}/tree/sub/file"] as $path) {
            chown($path, 4321);
            chgrp($path, 4321);
        }

        self::assertSame(0, TargetDirectory::chownTree("{$dir}/tree", 0, 0));
        foreach (["{$dir}/tree", "{$dir}/tree/sub", "{$dir}/tree/sub/file"] as $path) {
            clearstatcache();
            self::assertSame(0, fileowner($path), $path);
            self::assertSame(0, filegroup($path), $path);
        }
        self::assertSame(4321, fileowner("{$dir}/outside"), 'the symlink target is not touched');
        self::assertSame(0, TargetDirectory::chownTree("{$dir}/missing", 0, 0));
    }
}
