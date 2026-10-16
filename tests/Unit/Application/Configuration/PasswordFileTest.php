<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Configuration;

use Mfonte\Arkhive\Application\Configuration\PasswordFile;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * spec §4: the repository password file is private to the running user.
 */
final class PasswordFileTest extends TestCase
{
    use TempDir;

    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->file = "{$this->dir}/password";
        file_put_contents($this->file, "repo-password-placeholder\n");
        chmod($this->file, 0600);
    }

    private function refusal(string $path): ArkhiveException
    {
        try {
            PasswordFile::assertSafe($path);
        } catch (ArkhiveException $e) {
            return $e;
        }
        self::fail("{$path} was accepted");
    }

    public function testAPrivateFileIsAccepted(): void
    {
        PasswordFile::assertSafe($this->file);
        chmod($this->file, 0400);
        PasswordFile::assertSafe($this->file);

        $this->addToAssertionCount(2);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function modes(): iterable
    {
        yield 'readable by the group' => [0640, 'readable'];
        yield 'readable by others' => [0604, 'readable'];
        yield 'writable by the group' => [0620, 'writable'];
        yield 'writable by others' => [0602, 'writable'];
        yield 'executable by the group' => [0610, 'accessible'];
        yield 'readable and writable by everyone' => [0666, 'readable'];
    }

    #[DataProvider('modes')]
    public function testAnyPermissionForGroupOrOthersIsRefused(int $mode, string $access): void
    {
        chmod($this->file, $mode);

        $e = $this->refusal($this->file);

        self::assertInstanceOf(PermissionException::class, $e);
        self::assertSame(ExitCode::NO_PERMISSION, $e->exitCode());
        self::assertSame(sprintf('RESTIC_PASSWORD_FILE %s is %s by group or others (mode %04o)', $this->file, $access, $mode), $e->getMessage());
        self::assertSame("Run: chmod 600 {$this->file}", $e->hint());
    }

    public function testASymbolicLinkIsRefusedEvenToAPrivateFile(): void
    {
        $link = "{$this->dir}/link";
        symlink($this->file, $link);

        $e = $this->refusal($link);

        self::assertInstanceOf(PermissionException::class, $e);
        self::assertSame("RESTIC_PASSWORD_FILE {$link} is a symbolic link (to {$this->file}): whoever can change the link chooses the password", $e->getMessage());
        self::assertSame('Point RESTIC_PASSWORD_FILE at the file itself', $e->hint());
    }

    public function testADanglingSymbolicLinkIsALinkNotAMissingFile(): void
    {
        $link = "{$this->dir}/dangling";
        symlink("{$this->dir}/gone", $link);

        self::assertSame("RESTIC_PASSWORD_FILE {$link} is a symbolic link (to {$this->dir}/gone): whoever can change the link chooses the password", $this->refusal($link)->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function openDirectories(): iterable
    {
        yield 'world-writable' => [0777];
        yield 'group-writable' => [0770];
        yield 'writable by others only' => [0703];
    }

    #[DataProvider('openDirectories')]
    public function testADirectoryOthersCanWriteIsRefused(int $mode): void
    {
        chmod($this->dir, $mode);

        $e = $this->refusal($this->file);

        self::assertInstanceOf(PermissionException::class, $e);
        self::assertSame(sprintf('RESTIC_PASSWORD_FILE %s is in %s, which group or others can write (mode %04o): they could replace the file with one whose password they know', $this->file, $this->dir, $mode), $e->getMessage());
        self::assertSame("Run: chmod go-w {$this->dir}, or move the password file to a directory only its owner can write", $e->hint());
    }

    public function testAStickyDirectoryIsAccepted(): void
    {
        chmod($this->dir, 01777);

        PasswordFile::assertSafe($this->file);

        $this->addToAssertionCount(1);
    }

    public function testAFileOwnedBySomeoneElseIsRefused(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('changing the owner of a file needs root');
        }
        chown($this->file, 65534);

        $e = $this->refusal($this->file);

        self::assertInstanceOf(PermissionException::class, $e);
        self::assertSame("RESTIC_PASSWORD_FILE {$this->file} is owned by uid 65534, not by the running user (uid 0)", $e->getMessage());
        self::assertSame("Run: chown 0 {$this->file}", $e->hint());
    }

    public function testADirectoryOwnedBySomeoneElseIsRefusedEvenIfOnlyItsOwnerCanWriteIt(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('changing the owner of a directory needs root');
        }
        chmod($this->dir, 0755);
        chown($this->dir, 65534);

        $e = $this->refusal($this->file);

        self::assertInstanceOf(PermissionException::class, $e);
        self::assertSame("RESTIC_PASSWORD_FILE {$this->file} is in {$this->dir}, which belongs to uid 65534, not to the running user (uid 0) or root: its owner could replace the file, or remove it and stop every run", $e->getMessage());
        self::assertSame("Move the password file to a directory owned by uid 0 or root (such as /etc/arkhive), or run: chown 0 {$this->dir}", $e->hint());

        chmod($this->dir, 01777);
        self::assertInstanceOf(PermissionException::class, $this->refusal($this->file), 'sticky or not: its owner can remove the file');

        chown($this->dir, 0);
        PasswordFile::assertSafe($this->file);
    }

    public function testADirectoryOwnedByRootIsAcceptedForAnyUser(): void
    {
        clearstatcache();
        $stat = stat('/etc');
        self::assertIsArray($stat);
        self::assertSame(0, $stat['uid'], 'the fixture: /etc belongs to root');

        PasswordFile::assertPrivateDirectory('/etc/arkhive-password-that-does-not-exist');

        $this->addToAssertionCount(1);
    }

    public function testMissingEmptyAndNotAFile(): void
    {
        $missing = $this->refusal("{$this->dir}/gone");
        self::assertInstanceOf(NoInputException::class, $missing);
        self::assertSame("RESTIC_PASSWORD_FILE {$this->dir}/gone does not exist", $missing->getMessage());

        mkdir("{$this->dir}/sub", 0700);
        $directory = $this->refusal("{$this->dir}/sub");
        self::assertInstanceOf(NoInputException::class, $directory);
        self::assertSame("RESTIC_PASSWORD_FILE {$this->dir}/sub is not a regular file", $directory->getMessage());

        file_put_contents($this->file, '');
        $empty = $this->refusal($this->file);
        self::assertInstanceOf(ConfigException::class, $empty);
        self::assertSame("RESTIC_PASSWORD_FILE {$this->file} is empty", $empty->getMessage());
    }

    public function testTheOriginNamesWhereThePathCameFrom(): void
    {
        chmod($this->file, 0644);

        try {
            PasswordFile::assertSafe($this->file, '--password-file');
            self::fail('accepted');
        } catch (PermissionException $e) {
            self::assertStringStartsWith("--password-file {$this->file} is readable", $e->getMessage());
        }
    }
}
