<?php

declare(strict_types=1);

namespace Tests\Unit\Services\System;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\System\Lock;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;

final class LockTest extends TestCase
{
    use TempDir;

    public function testAcquireRecordsTheHolderAndReleaseTruncates(): void
    {
        $path = $this->tempDir() . '/test.lock';
        $lock = new Lock($path, new FixedClock());

        self::assertNull($lock->acquire('backup'));
        self::assertTrue($lock->held());
        self::assertNull($lock->acquire('backup'), 'acquiring twice is a no-op');
        self::assertSame(['pid' => (string) getmypid(), 'started' => '2026-10-12T10:20:30+02:00', 'operation' => 'backup'], $lock->holder());
        self::assertSame(0600, fileperms($path) & 0777);

        $lock->release();
        self::assertFalse($lock->held());
        self::assertFileExists($path, 'the file is kept so every run locks the same inode');
        self::assertSame('', file_get_contents($path));
        $lock->release();
    }

    public function testASecondHolderFailsImmediatelyNamingTheFirst(): void
    {
        $path = $this->tempDir() . '/test.lock';
        $first = new Lock($path, new FixedClock());
        $first->acquire('maintain');

        $second = new Lock($path, new FixedClock());
        try {
            $second->acquire('backup');
            self::fail('expected the lock to be refused');
        } catch (ArkhiveException $e) {
            self::assertSame(
                'Another ArkHive run holds the lock for this repository (PID ' . getmypid() . ', maintain, started 2026-10-12T10:20:30+02:00): ' . $path,
                $e->getMessage(),
            );
            self::assertSame(ExitCode::TEMPORARY, $e->exitCode());
        }
        self::assertFalse($second->held());

        $first->release();
        self::assertNull($second->acquire('backup'));
        $second->release();
    }

    public function testAnotherProcessHoldingTheLockIsDetected(): void
    {
        $path = $this->tempDir() . '/test.lock';
        $code = sprintf('$h = fopen(%s, "c+"); flock($h, LOCK_EX); fwrite($h, "pid=4242\nstarted=x\noperation=drill\n"); fflush($h); echo "locked\n"; fgets(STDIN);', var_export($path, true));
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("locked\n", fgets($pipes[1]));

        try {
            (new Lock($path, new FixedClock()))->acquire('backup');
            self::fail('expected the lock to be refused');
        } catch (ArkhiveException $e) {
            self::assertSame("Another ArkHive run holds the lock for this repository (PID 4242, drill, started x): {$path}", $e->getMessage());
        } finally {
            fwrite($pipes[0], "\n");
            fclose($pipes[0]);
            proc_close($process);
        }
    }

    public function testAStaleHolderIsReportedAndTheLockTaken(): void
    {
        $path = $this->tempDir() . '/test.lock';
        file_put_contents($path, "pid=999999\nstarted=2026-10-11T03:00:00+02:00\noperation=backup\n");

        $lock = new Lock($path, new FixedClock());
        self::assertSame(
            'A previous ArkHive run (PID 999999, backup, started 2026-10-11T03:00:00+02:00) ended without releasing its lock (killed or crashed); taking the lock over',
            $lock->acquire('backup'),
        );
        self::assertSame((string) getmypid(), $lock->holder()['pid']);
        $lock->release();
    }

    public function testGarbageInTheLockFileIsIgnored(): void
    {
        $path = $this->tempDir() . '/test.lock';
        file_put_contents($path, "junk\nother=1\n");

        $lock = new Lock($path, new FixedClock());
        self::assertNull($lock->acquire('backup'));
        $lock->release();
        self::assertSame([], (new Lock($this->tempDir() . '/none.lock', new FixedClock()))->holder());
    }

    public function testASymlinkIsRefused(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/victim", 'precious');
        symlink("{$dir}/victim", "{$dir}/test.lock");

        try {
            (new Lock("{$dir}/test.lock", new FixedClock()))->acquire('backup');
            self::fail('expected a refusal');
        } catch (ArkhiveException $e) {
            self::assertSame("Refusing the lock file {$dir}/test.lock: it is a symbolic link", $e->getMessage());
            self::assertSame('Remove it, or set ARKHIVE_LOCK_DIR to a private directory', $e->hint());
        }
        self::assertSame('precious', file_get_contents("{$dir}/victim"));
    }

    public function testAFileOwnedByAnotherUserIsRefused(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('needs root to create a file owned by another user');
        }
        $path = $this->tempDir() . '/test.lock';
        file_put_contents($path, '');
        chown($path, 4321);

        $this->expectExceptionObject(new ArkhiveException("Refusing the lock file {$path}: it belongs to uid 4321, not to the running user"));
        (new Lock($path, new FixedClock()))->acquire('backup');
    }

    public function testAnUnopenableLockFileIsAnError(): void
    {
        $this->expectExceptionObject(new ArkhiveException('Cannot open the lock file /nonexistent/dir/test.lock'));
        (new Lock('/nonexistent/dir/test.lock', new FixedClock()))->acquire('backup');
    }

    public function testDirectoryPrecedence(): void
    {
        $dir = $this->tempDir();
        foreach (['override', 'runlock', 'xdg'] as $sub) {
            mkdir("{$dir}/{$sub}");
        }

        self::assertSame("{$dir}/override", Lock::directory(['ARKHIVE_LOCK_DIR' => "{$dir}/override/", 'XDG_RUNTIME_DIR' => "{$dir}/xdg"], 0, "{$dir}/runlock"));
        self::assertSame("{$dir}/runlock", Lock::directory(['XDG_RUNTIME_DIR' => "{$dir}/xdg"], 0, "{$dir}/runlock"));
        self::assertSame("{$dir}/xdg", Lock::directory(['XDG_RUNTIME_DIR' => "{$dir}/xdg", 'ARKHIVE_LOCK_DIR' => 'relative'], 0, "{$dir}/missing"));
        self::assertSame(rtrim(sys_get_temp_dir(), '/'), Lock::directory(['XDG_RUNTIME_DIR' => "{$dir}/missing"], 999999, "{$dir}/missing"));
    }

    public function testOneLockFilePerRepository(): void
    {
        $dir = $this->tempDir();
        $env = ['ARKHIVE_LOCK_DIR' => $dir];
        $a = Lock::forRepository('sftp:backup@backup.example.com:/restic', $env, new FixedClock(), 0);
        $b = Lock::forRepository('sftp:backup@backup.example.com:/restic', $env, new FixedClock(), 0);
        $c = Lock::forRepository('/srv/restic-repo', $env, new FixedClock(), 0);

        self::assertSame($a->path(), $b->path());
        self::assertNotSame($a->path(), $c->path());
        self::assertMatchesRegularExpression('#^' . preg_quote($dir, '#') . '/arkhive-[0-9a-f]{16}\.lock$#', $a->path());
        self::assertStringNotContainsString('example.com', $a->path());
    }
}
