<?php

declare(strict_types=1);

namespace Tests\Unit\Application\System;

use Mfonte\Arkhive\Application\System\BinaryFinder;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\System\Dependency;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;
use Tests\Unit\Domain\System\DistroTest;

final class BinaryFinderTest extends TestCase
{
    use TempDir;

    private function executable(string $path, int $mode = 0755): string
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, "#!/bin/sh\n");
        chmod($path, $mode);

        return $path;
    }

    public function testPathIsScannedInOrderThenTheStandardDirectories(): void
    {
        $dir = $this->tempDir();
        $this->executable("{$dir}/b/restic");
        $this->executable("{$dir}/c/restic");
        $this->executable("{$dir}/std/pg_dump");
        $finder = new BinaryFinder("relative:{$dir}/a:{$dir}/b:{$dir}/c", ["{$dir}/std", "{$dir}/b"]);

        self::assertSame("{$dir}/b/restic", $finder->find('restic'));
        self::assertSame("{$dir}/std/pg_dump", $finder->find('pg_dump'));
        self::assertNull($finder->find('missing'));
        self::assertNull($finder->find(''));
        self::assertSame(["{$dir}/a", "{$dir}/b", "{$dir}/c", "{$dir}/std"], $finder->directories());
    }

    public function testNonExecutableFilesAndDirectoriesAreSkipped(): void
    {
        $dir = $this->tempDir();
        $this->executable("{$dir}/a/restic", 0644);
        mkdir("{$dir}/b/restic", 0755, true);
        $this->executable("{$dir}/c/restic");

        self::assertSame("{$dir}/c/restic", (new BinaryFinder("{$dir}/a:{$dir}/b:{$dir}/c", []))->find('restic'));
    }

    public function testExplicitPathsAreCheckedAsGiven(): void
    {
        $dir = $this->tempDir();
        $binary = $this->executable("{$dir}/opt/restic");
        $finder = new BinaryFinder('', []);

        self::assertSame($binary, $finder->find($binary));
        self::assertNull($finder->find("{$dir}/opt/nothing"));
        self::assertNull($finder->find('restic'));
    }

    public function testFirstPrefersEarlierNames(): void
    {
        $dir = $this->tempDir();
        $this->executable("{$dir}/bin/mysqldump");
        $finder = new BinaryFinder("{$dir}/bin", []);

        self::assertSame("{$dir}/bin/mysqldump", $finder->first(['mariadb-dump', 'mysqldump']));
        $this->executable("{$dir}/bin/mariadb-dump");
        self::assertSame("{$dir}/bin/mariadb-dump", $finder->first(['mariadb-dump', 'mysqldump']));
        self::assertNull($finder->first(['nope']));
    }

    public function testFromEnvironmentUsesPathAndCronSafeDefaults(): void
    {
        self::assertContains('/usr/local/bin', BinaryFinder::fromEnvironment()->directories());
        self::assertSame('/usr/local/sbin', BinaryFinder::STANDARD_DIRECTORIES[0]);
    }

    public function testMissingDependencyNamesTheInstallCommand(): void
    {
        $dependencies = new Dependencies(new BinaryFinder('', []), DistroTest::fixture('debian-12'));

        try {
            $dependencies->require(Dependency::PgDump, 'to dump PostgreSQL');
            self::fail('expected an exception');
        } catch (ArkhiveException $e) {
            self::assertSame('pg_dump is needed to dump PostgreSQL but was not found in PATH or the standard directories', $e->getMessage());
            self::assertSame('Run: apt install postgresql-client', $e->hint());
            self::assertSame(ExitCode::UNAVAILABLE, $e->exitCode());
        }
        self::assertNull($dependencies->find(Dependency::Restic));
        self::assertSame('debian', $dependencies->distro()->id);
    }

    public function testRequireReturnsTheFoundBinary(): void
    {
        $dir = $this->tempDir();
        $this->executable("{$dir}/bin/mariadb-dump");
        $dependencies = new Dependencies(new BinaryFinder("{$dir}/bin", []), DistroTest::fixture('alpine-3.20'));

        self::assertSame("{$dir}/bin/mariadb-dump", $dependencies->require(Dependency::MysqlDump, 'to dump MySQL'));
    }

    public function testConfiguredBinary(): void
    {
        $dir = $this->tempDir();
        $binary = $this->executable("{$dir}/opt/restic-0.19");
        $dependencies = new Dependencies(new BinaryFinder("{$dir}/opt", []), DistroTest::fixture('rocky-9'));

        self::assertSame($binary, $dependencies->requireConfigured($binary, Dependency::Restic, 'to back up', 'RESTIC_BINARY'));
        self::assertSame($binary, $dependencies->requireConfigured('restic-0.19', Dependency::Restic, 'to back up', 'RESTIC_BINARY'));

        try {
            $dependencies->requireConfigured('/opt/none/restic', Dependency::Restic, 'to back up', 'RESTIC_BINARY');
            self::fail('expected an exception');
        } catch (ArkhiveException $e) {
            self::assertSame('restic is needed to back up but /opt/none/restic is not an executable file (from RESTIC_BINARY)', $e->getMessage());
            self::assertSame('Run: dnf install epel-release, then: dnf install restic', $e->hint());
        }

        $this->expectExceptionObject(new ArkhiveException('restic is needed to back up but restic2 was not found in PATH or the standard directories (from RESTIC_BINARY)'));
        $dependencies->requireConfigured('restic2', Dependency::Restic, 'to back up', 'RESTIC_BINARY');
    }
}
