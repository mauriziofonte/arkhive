<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Output;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Output\LogFile;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class LogFileTest extends TestCase
{
    use TempDir;

    public function testLinesAreAppended(): void
    {
        $path = $this->tempDir() . '/a.log';
        file_put_contents($path, "old\n");
        $file = new LogFile($path);
        $file->write('one');
        $file->write('two');

        self::assertSame("old\none\ntwo\n", file_get_contents($path));
        self::assertSame($path, $file->path());
    }

    public function testRotatesBySizeAndKeepsTheConfiguredNumberOfFiles(): void
    {
        $path = $this->tempDir() . '/a.log';
        $file = new LogFile($path, 10, 2);

        foreach (['first-line', 'second-line', 'third-line', 'fourth-line'] as $line) {
            $file->write($line);
        }

        self::assertSame("fourth-line\n", file_get_contents($path));
        self::assertSame("third-line\n", file_get_contents("{$path}.1"));
        self::assertSame("second-line\n", file_get_contents("{$path}.2"));
        self::assertFileDoesNotExist("{$path}.3");
    }

    public function testDoesNotRotateBelowTheLimit(): void
    {
        $path = $this->tempDir() . '/a.log';
        $file = new LogFile($path, 100, 2);
        $file->write('short');
        $file->write('short');

        self::assertSame("short\nshort\n", file_get_contents($path));
        self::assertFileDoesNotExist("{$path}.1");
    }

    public function testAMissingDirectoryIsAUsageError(): void
    {
        $path = $this->tempDir() . '/missing/a.log';

        $this->expectExceptionObject(new UsageException(
            "Cannot write the log file {$path}: check that its directory exists and is writable",
        ));

        new LogFile($path);
    }

    public function testAnUnwritableFileIsAUsageError(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write read-only files');
        }
        $path = $this->tempDir() . '/a.log';
        touch($path);
        chmod($path, 0400);

        $this->expectException(UsageException::class);

        new LogFile($path);
    }
}
