<?php

declare(strict_types=1);

namespace Tests\Unit\Services\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The startup checks of bootstrap/requirements.php (old-PHP syntax, run before the autoloader).
 */
final class RequirementsTest extends TestCase
{
    private static function osRelease(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/os-release/' . $name);
    }

    public function testSupportedVersionsPass(): void
    {
        self::assertNull(arkhive_php_version_problem('8.3.0', null));
        self::assertNull(arkhive_php_version_problem('8.4.13', self::osRelease('debian-12')));
        self::assertNull(arkhive_php_version_problem('9.0.0', null));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oldPhpHints(): iterable
    {
        yield 'Debian 12 (sury)' => ['debian-12', 'Debian 12 does not ship it: install PHP 8.4 from https://packages.sury.org/php/, then run: apt install php8.4-cli php8.4-mbstring'];
        yield 'Ubuntu 22.04 (ondrej)' => ['ubuntu-22.04', 'Ubuntu before 24.04 does not ship it: run add-apt-repository ppa:ondrej/php, then: apt install php8.4-cli php8.4-mbstring'];
        yield 'Rocky 9' => ['rocky-9', 'Run: dnf module install php:8.3 (AppStream), or install PHP 8.3+ from the Remi repository (https://rpms.remirepo.net)'];
        yield 'RHEL 9' => ['rhel-9', 'Run: dnf module install php:8.3 (AppStream), or install PHP 8.3+ from the Remi repository (https://rpms.remirepo.net)'];
        yield 'Fedora' => ['fedora-42', 'Run: dnf install php-cli php-mbstring php-process'];
        yield 'Alpine' => ['alpine-3.20', 'Run: apk add php83 php83-phar php83-mbstring php83-posix php83-pcntl php83-curl (Alpine 3.20+)'];
        yield 'Arch' => ['arch', 'Run: pacman -S php'];
        yield 'openSUSE' => ['opensuse-leap-15', 'Run: zypper install php8 php8-phar php8-mbstring php8-posix php8-pcntl php8-curl'];
        yield 'unknown' => ['unknown-distro', "Install PHP 8.3 or newer with your distribution's package manager."];
    }

    #[DataProvider('oldPhpHints')]
    public function testOldPhpGetsTheDistroSpecificSource(string $file, string $hint): void
    {
        self::assertSame(
            'ArkHive needs PHP 8.3 or newer; this is PHP 8.2.7 (' . PHP_BINARY . ').' . PHP_EOL . $hint,
            arkhive_php_version_problem('8.2.7', self::osRelease($file)),
        );
    }

    public function testNoOsReleaseMeansUnknown(): void
    {
        self::assertStringEndsWith("Install PHP 8.3 or newer with your distribution's package manager.", (string) arkhive_php_version_problem('7.4.33', false));
        self::assertSame(['id' => 'unknown', 'like' => [], 'version' => ''], arkhive_os_release(null));
    }

    public function testMissingExtensionsNameThePackagesForTheRunningPhp(): void
    {
        self::assertNull(arkhive_extensions_problem([], '8.4.1', null));
        self::assertSame(
            'ArkHive needs the PHP extensions mbstring, pcntl, not loaded by ' . PHP_BINARY . '.' . PHP_EOL . 'Run: apk add php83-mbstring php83-pcntl',
            arkhive_extensions_problem(['mbstring', 'pcntl'], '8.3.12', self::osRelease('alpine-3.20')),
        );
        self::assertSame(
            'ArkHive needs the PHP extension posix, not loaded by ' . PHP_BINARY . '.' . PHP_EOL . 'Run: apt install php8.3-cli',
            arkhive_extensions_problem(['posix'], '8.3.12', self::osRelease('debian-13')),
        );
        self::assertSame(
            'ArkHive needs the PHP extensions posix, pcntl, not loaded by ' . PHP_BINARY . '.' . PHP_EOL . 'Run: dnf install php-process',
            arkhive_extensions_problem(['posix', 'pcntl'], '8.3.12', self::osRelease('almalinux-9')),
        );
        self::assertSame(
            'ArkHive needs the PHP extension curl, not loaded by ' . PHP_BINARY . '.' . PHP_EOL . 'Run: pacman -S php (and enable the extension in /etc/php/php.ini if it is commented out)',
            arkhive_extensions_problem(['curl'], '8.4.1', self::osRelease('arch')),
        );
        self::assertSame(
            'ArkHive needs the PHP extensions mbstring, posix, not loaded by ' . PHP_BINARY . '.' . PHP_EOL . "Install them with your distribution's package manager.",
            arkhive_extensions_problem(['mbstring', 'posix'], '8.4.1', self::osRelease('unknown-distro')),
        );
        self::assertSame(
            'ArkHive needs the PHP extension pcntl, not loaded by ' . PHP_BINARY . '.' . PHP_EOL . "Install it with your distribution's package manager.",
            arkhive_extensions_problem(['pcntl'], '8.4.1', null),
        );
    }

    public function testRequiredExtensionsAndTheCurrentPhpPasses(): void
    {
        self::assertSame(['phar', 'json', 'mbstring', 'posix', 'pcntl'], arkhive_required_extensions());
        self::assertSame([], arkhive_startup_problems());
    }

    public function testExtensionPackageForUnknownFamilyIsNull(): void
    {
        self::assertNull(arkhive_php_extension_package('curl', 'unknown', '8.4.1'));
        self::assertSame('php8-curl', arkhive_php_extension_package('curl', 'suse', '8.4'));
        self::assertSame('php8.4-cli', arkhive_php_extension_package('phar', 'debian', '8.4'));
    }

    /**
     * The file must stay parseable by the PHP versions it is meant to reject.
     */
    public function testRequirementsFileUsesNoModernSyntax(): void
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents(dirname(__DIR__, 4) . '/bootstrap/requirements.php'));
        $forbidden = [T_FN, T_MATCH, T_ENUM, T_READONLY, T_NULLSAFE_OBJECT_OPERATOR, T_COALESCE_EQUAL, T_ATTRIBUTE, T_ELLIPSIS];
        foreach ($tokens as $token) {
            self::assertNotContains($token->id, $forbidden, "line {$token->line}: {$token->text}");
        }
    }
}
