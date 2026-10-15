<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Database;

use Mfonte\Arkhive\Application\Database\DumpStaging;
use Mfonte\Arkhive\Application\Database\SecretFile;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class DumpStagingTest extends TestCase
{
    use TempDir;

    public function testPrepareCreatesPrivateDirectories(): void
    {
        $dir = $this->tempDir() . '/a/b/dumps';
        $staging = new DumpStaging($dir);

        self::assertSame([], $staging->prepare());
        foreach (['', '/mysql', '/pgsql'] as $sub) {
            self::assertDirectoryExists($dir . $sub);
            self::assertSame(0700, fileperms($dir . $sub) & 0777);
        }
        self::assertSame([], $staging->prepare(), 'idempotent');
        self::assertIsFloat($staging->freeSpace());
    }

    public function testOnlyDumpFilesAreEverRemoved(): void
    {
        $dir = $this->tempDir() . '/dumps';
        $staging = new DumpStaging($dir);
        $staging->prepare();
        $outside = $this->tempDir() . '/precious.sql';
        file_put_contents($outside, 'keep');
        file_put_contents("{$dir}/mysql/a.sql", 'a');
        file_put_contents("{$dir}/pgsql/b.sql.partial", 'b');
        file_put_contents("{$dir}/pgsql/notes.txt", 'keep');
        file_put_contents("{$dir}/top.sql", 'keep: not in an engine directory');
        mkdir("{$dir}/mysql/dir.sql");
        symlink($outside, "{$dir}/mysql/link.sql");

        self::assertSame(2, $staging->clear());
        self::assertFileDoesNotExist("{$dir}/mysql/a.sql");
        self::assertFileDoesNotExist("{$dir}/pgsql/b.sql.partial");
        self::assertFileExists("{$dir}/pgsql/notes.txt");
        self::assertFileExists("{$dir}/top.sql");
        self::assertDirectoryExists("{$dir}/mysql/dir.sql");
        self::assertTrue(is_link("{$dir}/mysql/link.sql"));
        self::assertSame('keep', file_get_contents($outside));
        self::assertSame(0, $staging->clear());
    }

    public function testPathsAndRecording(): void
    {
        $staging = new DumpStaging('/var/backups/arkhive-dumps');

        self::assertSame('/var/backups/arkhive-dumps/mysql/shop.sql', $staging->pathFor('mysql', 'shop'));
        self::assertSame('/var/backups/arkhive-dumps/pgsql/a%2Fb%20c.sql', $staging->pathFor('pgsql', 'a/b c'));
        self::assertSame('/x.sql.partial', DumpStaging::partial('/x.sql'));
        $staging->record('/a.sql');
        $staging->record('/a.sql');
        $staging->record('/b.sql');
        self::assertSame(['/a.sql', '/b.sql'], $staging->written());
        $staging->clear();
        self::assertSame([], $staging->written());
        self::assertNull($staging->freeSpace());
    }

    public function testAnUncreatableDirectoryIsAnError(): void
    {
        $file = $this->tempDir() . '/file';
        file_put_contents($file, '');

        $this->expectExceptionObject(new ArkhiveException("Cannot create the dump directory {$file}/dumps"));
        (new DumpStaging("{$file}/dumps"))->prepare();
    }

    public function testAnUnwritableDirectoryIsAnError(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $dir = $this->tempDir() . '/dumps';
        mkdir($dir, 0500);

        $this->expectExceptionObject(new ArkhiveException("The dump directory {$dir} is not writable"));
        (new DumpStaging($dir))->prepare();
    }

    public function testSecretFilesArePrivateAndRemovedOnce(): void
    {
        $dir = $this->tempDir();
        $file = SecretFile::create('arkhive-test-', "password=x\n", $dir);

        self::assertStringStartsWith("{$dir}/arkhive-test-", $file->path);
        self::assertSame(0600, fileperms($file->path) & 0777);
        self::assertSame("password=x\n", file_get_contents($file->path));
        $file->remove();
        self::assertFileDoesNotExist($file->path);
        file_put_contents($file->path, 'someone else');
        $file->remove();
        self::assertFileExists($file->path, 'a second remove() never deletes a file created later');
    }

    public function testSecretFileInAMissingDirectoryIsAnError(): void
    {
        $this->expectExceptionObject(new ArkhiveException('Cannot create a temporary credentials file in /nonexistent/tmp'));
        SecretFile::create('x', 'y', '/nonexistent/tmp');
    }

    public function testBytes(): void
    {
        self::assertSame('0 B', Bytes::format(-5));
        self::assertSame('1023 B', Bytes::format(1023));
        self::assertSame('1.00 KiB', Bytes::format(1024));
        self::assertSame('1.50 MiB', Bytes::format(1572864));
        self::assertSame('2.00 GiB', Bytes::format(2 * 1024 ** 3));
        self::assertSame('1024.00 PiB', Bytes::format(1024 ** 6));
    }
}
