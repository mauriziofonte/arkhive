<?php

declare(strict_types=1);

namespace Tests\Unit\Application\System;

use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
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

    public function testAFileOwnedByAnotherUserIsRefusedWithTheRealCause(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('needs root to create a file owned by another user');
        }
        $path = $this->tempDir() . '/test.lock';
        file_put_contents($path, '');
        chown($path, 4321);

        try {
            (new Lock($path, new FixedClock()))->acquire('backup');
            self::fail('expected a refusal');
        } catch (ArkhiveException $e) {
            self::assertSame("Refusing the lock file {$path}: it belongs to uid 4321, not to the running user (uid 0), in a shared directory", $e->getMessage());
            self::assertSame('Another user created it first, which blocks ArkHive: remove it, or set ARKHIVE_LOCK_DIR to a private directory', $e->hint());
            self::assertSame(ExitCode::NO_PERMISSION, $e->exitCode());
        }
    }

    /**
     * The local DoS of 2.1.0-rc.1: an unprivileged user creates root's lock file in the world-writable
     * sticky /run/lock first. With fs.protected_regular=1 root's O_CREAT open then fails with EACCES,
     * which was reported as an unwritable directory (73). Root no longer locks there at all; a shared
     * directory still names the real cause (77).
     */
    public function testAFileAnotherUserPlantedInAStickyDirectoryIsNamedAsSuch(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('needs root to create a file owned by another user');
        }
        $shared = $this->tempDir() . '/lock';
        mkdir($shared);
        chmod($shared, 01777);
        $planted = Lock::forRepository('sftp:u123456-sub1@backup.example.com:/home/arkhive', ['ARKHIVE_LOCK_DIR' => $shared], new FixedClock(), 0);
        touch($planted->path());
        chown($planted->path(), 65534);

        try {
            $planted->acquire('backup');
            self::fail('expected a refusal');
        } catch (ArkhiveException $e) {
            self::assertSame(ExitCode::NO_PERMISSION, $e->exitCode());
            self::assertStringContainsString('it belongs to uid 65534, not to the running user (uid 0), in a shared directory', $e->getMessage());
        }

        $private = $this->tempDir() . '/private';
        $root = Lock::forRepository('sftp:u123456-sub1@backup.example.com:/home/arkhive', [], new FixedClock(), 0, $shared, $private);
        self::assertSame("{$private}/" . basename($planted->path()), $root->path(), 'root ignores the shared directory');
        self::assertNull($root->acquire('backup'));
        $root->release();
    }

    public function testRootCreatesItsPrivateLockDirectoryOnlyWhenLocking(): void
    {
        $private = $this->tempDir() . '/run-arkhive';
        $lock = Lock::forRepository('/srv/restic-repo', [], new FixedClock(), 0, $this->tempDir(), $private);

        self::assertSame([], $lock->holder());
        self::assertDirectoryDoesNotExist($private, 'reading the holder (doctor, status) creates nothing');
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('the private directory is root\'s');
        }
        self::assertNull($lock->acquire('backup'));
        clearstatcache();
        self::assertSame(040700, fileperms($private) & 0177777);
        self::assertSame(0, fileowner($private));
        $lock->release();
    }

    public function testAnExistingPrivateDirectoryIsMadePrivateOrRefused(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('the private directory is root\'s');
        }
        $dir = $this->tempDir();
        mkdir("{$dir}/open", 0777);
        chmod("{$dir}/open", 0777);
        $lock = Lock::forRepository('/srv/restic-repo', [], new FixedClock(), 0, "{$dir}/none", "{$dir}/open");
        self::assertNull($lock->acquire('backup'));
        clearstatcache();
        self::assertSame(0700, fileperms("{$dir}/open") & 0777);
        $lock->release();

        mkdir("{$dir}/target");
        symlink("{$dir}/target", "{$dir}/link");
        $this->assertRefused(Lock::forRepository('/srv/restic-repo', [], new FixedClock(), 0, "{$dir}/none", "{$dir}/link"), "Refusing the lock directory {$dir}/link: it is not a directory (a symbolic link?)");

        mkdir("{$dir}/theirs", 0700);
        chown("{$dir}/theirs", 4321);
        $this->assertRefused(Lock::forRepository('/srv/restic-repo', [], new FixedClock(), 0, "{$dir}/none", "{$dir}/theirs"), "Refusing the lock directory {$dir}/theirs: it belongs to uid 4321, not to the running user (uid 0)");
    }

    private function assertRefused(Lock $lock, string $message): void
    {
        try {
            $lock->acquire('backup');
            self::fail('expected a refusal');
        } catch (ArkhiveException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame(ExitCode::NO_PERMISSION, $e->exitCode());
        }
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

        // another user: the shared /run/lock first, so that cron and login shells meet
        self::assertSame("{$dir}/override", Lock::directory(['ARKHIVE_LOCK_DIR' => "{$dir}/override/", 'XDG_RUNTIME_DIR' => "{$dir}/xdg"], 1000, "{$dir}/runlock"));
        self::assertSame("{$dir}/runlock", Lock::directory(['XDG_RUNTIME_DIR' => "{$dir}/xdg"], 1000, "{$dir}/runlock"));
        self::assertSame("{$dir}/xdg", Lock::directory(['XDG_RUNTIME_DIR' => "{$dir}/xdg", 'ARKHIVE_LOCK_DIR' => 'relative'], 1000, "{$dir}/missing"));
        self::assertSame(rtrim(sys_get_temp_dir(), '/'), Lock::directory(['XDG_RUNTIME_DIR' => "{$dir}/missing"], 999999, "{$dir}/missing"));

        // root: its private directory, whatever is writable, unless ARKHIVE_LOCK_DIR says otherwise
        self::assertSame('/run/arkhive', Lock::directory(['XDG_RUNTIME_DIR' => "{$dir}/xdg"], 0, "{$dir}/runlock"));
        self::assertSame("{$dir}/private", Lock::directory([], 0, "{$dir}/runlock", "{$dir}/private"));
        self::assertSame("{$dir}/override", Lock::directory(['ARKHIVE_LOCK_DIR' => "{$dir}/override"], 0, "{$dir}/runlock", "{$dir}/private"));
        self::assertSame("{$dir}/private", Lock::directory(['ARKHIVE_LOCK_DIR' => "{$dir}/missing"], 0, "{$dir}/runlock", "{$dir}/private"));
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
