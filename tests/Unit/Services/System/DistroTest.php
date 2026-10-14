<?php

declare(strict_types=1);

namespace Tests\Unit\Services\System;

use Mfonte\Arkhive\Services\System\Dependency;
use Mfonte\Arkhive\Services\System\Distro;
use Mfonte\Arkhive\Services\System\InstallHints;
use Mfonte\Arkhive\Services\System\PackageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class DistroTest extends TestCase
{
    use TempDir;

    public static function fixture(string $name): Distro
    {
        return Distro::fromOsRelease((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/os-release/' . $name));
    }

    /**
     * Every distribution of spec §12, plus one that ArkHive does not know.
     *
     * @return iterable<string, array{string, string, list<string>, string, string, ?PackageManager}>
     */
    public static function distributions(): iterable
    {
        yield 'Debian 12' => ['debian-12', 'debian', [], '12', 'Debian GNU/Linux 12 (bookworm)', PackageManager::Apt];
        yield 'Debian 13' => ['debian-13', 'debian', [], '13', 'Debian GNU/Linux 13 (trixie)', PackageManager::Apt];
        yield 'Ubuntu 22.04' => ['ubuntu-22.04', 'ubuntu', ['debian'], '22.04', 'Ubuntu 22.04.5 LTS', PackageManager::Apt];
        yield 'Ubuntu 24.04' => ['ubuntu-24.04', 'ubuntu', ['debian'], '24.04', 'Ubuntu 24.04.3 LTS', PackageManager::Apt];
        yield 'Rocky 9' => ['rocky-9', 'rocky', ['rhel', 'centos', 'fedora'], '9.6', 'Rocky Linux 9.6 (Blue Onyx)', PackageManager::Dnf];
        yield 'AlmaLinux 9' => ['almalinux-9', 'almalinux', ['rhel', 'centos', 'fedora'], '9.6', 'AlmaLinux 9.6 (Sage Margay)', PackageManager::Dnf];
        yield 'RHEL 9' => ['rhel-9', 'rhel', ['fedora'], '9.6', 'Red Hat Enterprise Linux 9.6 (Plow)', PackageManager::Dnf];
        yield 'Fedora' => ['fedora-42', 'fedora', [], '42', 'Fedora Linux 42 (Container Image)', PackageManager::Dnf];
        yield 'Alpine 3.20' => ['alpine-3.20', 'alpine', [], '3.20.6', 'Alpine Linux v3.20', PackageManager::Apk];
        yield 'Arch' => ['arch', 'arch', [], '', 'Arch Linux', PackageManager::Pacman];
        yield 'openSUSE Tumbleweed' => ['opensuse-tumbleweed', 'opensuse-tumbleweed', ['opensuse', 'suse'], '20251010', 'openSUSE Tumbleweed', PackageManager::Zypper];
        yield 'openSUSE Leap' => ['opensuse-leap-15', 'opensuse-leap', ['suse', 'opensuse'], '15.6', 'openSUSE Leap 15.6', PackageManager::Zypper];
        yield 'unknown' => ['unknown-distro', 'exampleos', [], '1', 'Example OS 1', null];
    }

    /**
     * @param list<string> $like
     */
    #[DataProvider('distributions')]
    public function testOsReleaseParsing(string $file, string $id, array $like, string $version, string $pretty, ?PackageManager $manager): void
    {
        $distro = self::fixture($file);

        self::assertSame($id, $distro->id);
        self::assertSame($like, $distro->idLike);
        self::assertSame($version, $distro->versionId);
        self::assertSame($pretty, $distro->describe());
        self::assertSame($manager, $distro->packageManager());
    }

    public function testEnterpriseLinuxIsRhelAndItsRebuildsButNotFedora(): void
    {
        foreach (['rocky-9', 'almalinux-9', 'rhel-9'] as $file) {
            self::assertTrue(self::fixture($file)->isEnterpriseLinux(), $file);
        }
        foreach (['fedora-42', 'debian-12', 'alpine-3.20'] as $file) {
            self::assertFalse(self::fixture($file)->isEnterpriseLinux(), $file);
        }
    }

    public function testDetectReadsTheFirstReadableFileAndFallsBackToUnknown(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/os-release", "ID=alpine\nVERSION_ID=3.21.0\n");

        self::assertSame('alpine', Distro::detect(["{$dir}/missing", "{$dir}/os-release"])->id);
        $unknown = Distro::detect(["{$dir}/missing"]);
        self::assertSame('unknown', $unknown->id);
        self::assertSame('unknown', $unknown->describe());
        self::assertNull($unknown->packageManager());
    }

    public function testQuotingAndEscapesFollowOsReleaseRules(): void
    {
        $distro = Distro::fromOsRelease("ID='Debian'\nPRETTY_NAME=\"A \\\"quoted\\\" \\\$name\"\n  # comment\nnot a line\n");

        self::assertSame('debian', $distro->id);
        self::assertSame('A "quoted" $name', $distro->prettyName);
        self::assertSame('unknown', Distro::fromOsRelease('')->id);
    }

    public function testIdLikeFallbackForDerivatives(): void
    {
        self::assertSame(PackageManager::Apt, Distro::fromOsRelease("ID=pop\nID_LIKE=\"ubuntu debian\"")->packageManager());
        self::assertSame(PackageManager::Dnf, Distro::fromOsRelease("ID=nobara\nID_LIKE=fedora")->packageManager());
        self::assertSame(PackageManager::Pacman, Distro::fromOsRelease("ID=manjaro\nID_LIKE=arch")->packageManager());
        self::assertSame('suse', Distro::fromOsRelease('ID=sles')->family());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resticHints(): iterable
    {
        yield 'Debian 12' => ['debian-12', 'Run: apt install restic'];
        yield 'Ubuntu 24.04' => ['ubuntu-24.04', 'Run: apt install restic'];
        yield 'Rocky 9' => ['rocky-9', 'Run: dnf install epel-release, then: dnf install restic'];
        yield 'AlmaLinux 9' => ['almalinux-9', 'Run: dnf install epel-release, then: dnf install restic'];
        yield 'RHEL 9' => ['rhel-9', 'Run: dnf install epel-release, then: dnf install restic'];
        yield 'Fedora' => ['fedora-42', 'Run: dnf install restic'];
        yield 'Alpine' => ['alpine-3.20', 'Run: apk add restic'];
        yield 'Arch' => ['arch', 'Run: pacman -S restic'];
        yield 'openSUSE' => ['opensuse-tumbleweed', 'Run: zypper install restic'];
        yield 'unknown' => ['unknown-distro', "Install restic with your distribution's package manager"];
    }

    #[DataProvider('resticHints')]
    public function testResticInstallHint(string $file, string $hint): void
    {
        self::assertSame($hint, InstallHints::forDependency(Dependency::Restic, self::fixture($file)));
    }

    /**
     * Package names per dependency and package manager, verbatim.
     *
     * @return iterable<string, array{Dependency, array<string, string>}>
     */
    public static function dependencyPackages(): iterable
    {
        yield 'mariadb-dump' => [Dependency::MysqlDump, ['apt' => 'mariadb-client', 'dnf' => 'mariadb', 'apk' => 'mariadb-client', 'pacman' => 'mariadb-clients', 'zypper' => 'mariadb-client']];
        yield 'mariadb client' => [Dependency::MysqlClient, ['apt' => 'mariadb-client', 'dnf' => 'mariadb', 'apk' => 'mariadb-client', 'pacman' => 'mariadb-clients', 'zypper' => 'mariadb-client']];
        yield 'pg_dump' => [Dependency::PgDump, ['apt' => 'postgresql-client', 'dnf' => 'postgresql', 'apk' => 'postgresql-client', 'pacman' => 'postgresql', 'zypper' => 'postgresql']];
        yield 'psql' => [Dependency::PgClient, ['apt' => 'postgresql-client', 'dnf' => 'postgresql', 'apk' => 'postgresql-client', 'pacman' => 'postgresql', 'zypper' => 'postgresql']];
        yield 'ssh' => [Dependency::Ssh, ['apt' => 'openssh-client', 'dnf' => 'openssh-clients', 'apk' => 'openssh-client', 'pacman' => 'openssh', 'zypper' => 'openssh-clients']];
        yield 'openssl' => [Dependency::Openssl, ['apt' => 'openssl', 'dnf' => 'openssl', 'apk' => 'openssl', 'pacman' => 'openssl', 'zypper' => 'openssl']];
        yield 'xz' => [Dependency::Xz, ['apt' => 'xz-utils', 'dnf' => 'xz', 'apk' => 'xz', 'pacman' => 'xz', 'zypper' => 'xz']];
        yield 'tar' => [Dependency::Tar, ['apt' => 'tar', 'dnf' => 'tar', 'apk' => 'tar', 'pacman' => 'tar', 'zypper' => 'tar']];
        yield 'gh' => [Dependency::Gh, ['apt' => 'gh', 'dnf' => 'gh', 'apk' => 'github-cli', 'pacman' => 'github-cli', 'zypper' => 'gh']];
        yield 'fuse' => [Dependency::Fuse, ['apt' => 'fuse3', 'dnf' => 'fuse3', 'apk' => 'fuse3', 'pacman' => 'fuse3', 'zypper' => 'fuse3']];
    }

    /**
     * @param array<string, string> $packages
     */
    #[DataProvider('dependencyPackages')]
    public function testDependencyPackagesPerManager(Dependency $dependency, array $packages): void
    {
        $fixtures = ['apt' => 'debian-12', 'dnf' => 'rocky-9', 'apk' => 'alpine-3.20', 'pacman' => 'arch', 'zypper' => 'opensuse-leap-15'];
        foreach ($packages as $manager => $package) {
            self::assertSame([$package], $dependency->packages(PackageManager::from($manager)));
            $verb = ['apt' => 'apt install', 'dnf' => 'dnf install', 'apk' => 'apk add', 'pacman' => 'pacman -S', 'zypper' => 'zypper install'][$manager];
            self::assertSame("Run: {$verb} {$package}", InstallHints::forDependency($dependency, self::fixture($fixtures[$manager])));
        }
    }

    public function testBinaryNamesPreferMariadbAndFuse3(): void
    {
        self::assertSame(['mariadb-dump', 'mysqldump'], Dependency::MysqlDump->binaries());
        self::assertSame('mariadb-dump or mysqldump', Dependency::MysqlDump->label());
        self::assertSame(['mariadb', 'mysql'], Dependency::MysqlClient->binaries());
        self::assertSame(['fusermount3', 'fusermount'], Dependency::Fuse->binaries());
        self::assertSame('pg_dump', Dependency::PgDump->label());
        self::assertSame(['ssh'], Dependency::Ssh->binaries());
        self::assertSame(['openssl'], Dependency::Openssl->binaries());
        self::assertSame(['xz'], Dependency::Xz->binaries());
        self::assertSame(['tar'], Dependency::Tar->binaries());
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function phpExtensionHints(): iterable
    {
        yield 'Debian' => ['debian-12', ['mbstring', 'curl', 'pcntl'], 'Run: apt install php8.4-mbstring php8.4-curl php8.4-cli'];
        yield 'Ubuntu' => ['ubuntu-22.04', ['posix', 'phar'], 'Run: apt install php8.4-cli'];
        yield 'Rocky' => ['rocky-9', ['mbstring', 'posix', 'pcntl', 'curl', 'phar'], 'Run: dnf install php-mbstring php-process php-common php-cli'];
        yield 'Fedora' => ['fedora-42', ['pcntl'], 'Run: dnf install php-process'];
        yield 'Alpine' => ['alpine-3.20', ['mbstring', 'posix', 'pcntl', 'json'], 'Run: apk add php84-mbstring php84-posix php84-pcntl php84'];
        yield 'Arch' => ['arch', ['curl'], 'Run: pacman -S php'];
        yield 'openSUSE' => ['opensuse-tumbleweed', ['mbstring', 'json'], 'Run: zypper install php8-mbstring php8'];
        yield 'unknown' => ['unknown-distro', ['pcntl'], "Install the PHP extension(s) pcntl with your distribution's package manager"];
    }

    /**
     * @param list<string> $extensions
     */
    #[DataProvider('phpExtensionHints')]
    public function testPhpExtensionHints(string $file, array $extensions, string $hint): void
    {
        self::assertSame($hint, InstallHints::forPhpExtensions($extensions, self::fixture($file), '8.4.13'));
    }
}
