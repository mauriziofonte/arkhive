<?php

declare(strict_types=1);

namespace Tests\Unit\Application\System;

use Mfonte\Arkhive\Application\System\DiskSpace;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class DiskSpaceTest extends TestCase
{
    use TempDir;

    private const FREE = '\d+(?:\.\d\d [KMGTPE]iB| B)';

    public function testTheNearestDirectoryThatExists(): void
    {
        $dir = $this->tempDir();

        self::assertSame($dir, DiskSpace::nearestDirectory($dir));
        self::assertSame($dir, DiskSpace::nearestDirectory("{$dir}/new/deeper/file.sql"));
        self::assertSame('/', DiskSpace::nearestDirectory('/nonexistent-arkhive-test/x'));
        self::assertSame('.', DiskSpace::nearestDirectory('relative/missing'));
    }

    public function testTheFreeSpaceOfAPathThatDoesNotExistYet(): void
    {
        $dir = $this->tempDir();
        $free = DiskSpace::available("{$dir}/new/file");

        self::assertIsInt($free);
        self::assertGreaterThan(0, $free);
        self::assertEqualsWithDelta((float) disk_free_space($dir), (float) $free, 64 * 1024 * 1024);
        self::assertMatchesRegularExpression('~^' . self::FREE . ' free in ' . preg_quote($dir, '~') . '$~', DiskSpace::describe("{$dir}/new/file"));
    }

    public function testAnnotatingAddsTheFreeSpaceOfEachFilesystemOnce(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/repo");
        $failure = new IoException('restic backup failed (restic exit 1): no space left on device', 'Free space');

        $annotated = DiskSpace::annotate($failure, ["{$dir}/repo", "{$dir}/cache/restic", "{$dir}/other"]);

        $quoted = preg_quote($dir, '~');
        self::assertMatchesRegularExpression('~^restic backup failed \(restic exit 1\): no space left on device \(' . self::FREE . " free in {$quoted}/repo, " . self::FREE . " free in {$quoted}\\)$~", $annotated->getMessage());
        self::assertSame('Free space', $annotated->hint());
        self::assertSame(ExitCode::IO_ERROR, $annotated->exitCode());
        self::assertSame($failure, $annotated->getPrevious());
        self::assertSame($failure, DiskSpace::annotate($failure, []), 'nothing to add');
    }

    public function testWhenFullAnnotatesOnlyWriteFailures(): void
    {
        $dir = $this->tempDir();

        self::assertSame(42, DiskSpace::whenFull(static fn (): int => 42, $dir));
        try {
            DiskSpace::whenFull(static fn (): never => throw new IoException('pg_dump failed: No space left on device'), "{$dir}/staging");
            self::fail('expected a failure');
        } catch (IoException $e) {
            self::assertMatchesRegularExpression('~^pg_dump failed: No space left on device \(' . self::FREE . ' free in ' . preg_quote($dir, '~') . '\)$~', $e->getMessage());
        }
        $other = new ArkhiveException('refused', ExitCode::NO_PERMISSION);
        try {
            DiskSpace::whenFull(static fn (): never => throw $other, $dir);
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame($other, $e);
        }
    }
}
