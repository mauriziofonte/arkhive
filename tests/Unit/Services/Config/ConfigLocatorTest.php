<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Config;

use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Services\Config\ConfigLocator;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class ConfigLocatorTest extends TestCase
{
    use TempDir;

    public function testDiscoveryOrderIsDocumentedAndStartsFromTheWorkingDirectory(): void
    {
        $locator = new ConfigLocator('/work/dir', '/home/me', '/home/me/.xdg');

        self::assertSame([
            '/work/dir/.arkhive-config',
            '/work/dir/.config/arkhive-config',
            '/work/dir/.config/arkhive/config',
            '/home/me/.xdg/arkhive/config',
            '/home/me/.arkhive-config',
            '/home/me/.config/arkhive-config',
            '/home/me/.config/arkhive/config',
            '/etc/arkhive-config',
            '/etc/arkhive/config',
        ], $locator->candidates());
    }

    public function testDuplicatesAndARelativeXdgAreIgnored(): void
    {
        $locator = new ConfigLocator('/home/me', '/home/me', 'relative/xdg');

        self::assertSame([
            '/home/me/.arkhive-config',
            '/home/me/.config/arkhive-config',
            '/home/me/.config/arkhive/config',
            '/etc/arkhive-config',
            '/etc/arkhive/config',
        ], $locator->candidates());
    }

    public function testXdgEqualToTheDefaultIsNotListedTwice(): void
    {
        $locator = new ConfigLocator('/w', '/home/me', '/home/me/.config/');

        self::assertSame(1, count(array_keys($locator->candidates(), '/home/me/.config/arkhive/config', true)));
    }

    public function testWithoutHomeOnlyWorkingDirectoryXdgAndEtcAreSearched(): void
    {
        self::assertSame([
            '/w/.arkhive-config', '/w/.config/arkhive-config', '/w/.config/arkhive/config', '/etc/arkhive-config', '/etc/arkhive/config',
        ], (new ConfigLocator('/w/', ''))->candidates());
    }

    public function testTheFirstExistingCandidateWins(): void
    {
        $cwd = $this->tempDir();
        $home = $this->tempDir();
        mkdir("{$cwd}/.config/arkhive", 0700, true);
        touch("{$cwd}/.config/arkhive/config");
        touch("{$home}/.arkhive-config");

        self::assertSame("{$cwd}/.config/arkhive/config", (new ConfigLocator($cwd, $home))->locate());
    }

    public function testExplicitConfigBypassesDiscovery(): void
    {
        $cwd = $this->tempDir();
        touch("{$cwd}/.arkhive-config");
        $explicit = $this->tempDir() . '/custom.conf';
        touch($explicit);

        self::assertSame($explicit, (new ConfigLocator($cwd, ''))->locate($explicit));
    }

    public function testMissingExplicitConfigIsNoInput(): void
    {
        $this->expectExceptionObject(new NoInputException('Configuration file /nonexistent/arkhive.conf (from --config) does not exist or is not readable'));

        (new ConfigLocator('/', ''))->locate('/nonexistent/arkhive.conf');
    }

    public function testNothingFoundListsEveryCandidate(): void
    {
        $cwd = $this->tempDir();
        $locator = new ConfigLocator($cwd, '');
        try {
            $locator->locate();
            self::fail('expected a configuration error');
        } catch (ConfigException $e) {
            if (is_file('/etc/arkhive-config') || is_file('/etc/arkhive/config')) {
                self::markTestSkipped('this machine has a system-wide ArkHive config');
            }
            self::assertSame(
                "No ArkHive configuration file found. Looked for, in this order:\n  {$cwd}/.arkhive-config\n  {$cwd}/.config/arkhive-config\n  {$cwd}/.config/arkhive/config\n  /etc/arkhive-config\n  /etc/arkhive/config",
                $e->getMessage(),
            );
            self::assertSame('Create one from .arkhive-config.example, or pass --config <path>', $e->hint());
        }
    }

    public function testFromEnvironmentUsesTheWorkingDirectoryHomeAndXdg(): void
    {
        $candidates = ConfigLocator::fromEnvironment(['HOME' => '/home/env', 'XDG_CONFIG_HOME' => '/xdg'])->candidates();

        self::assertSame((string) getcwd() . '/.arkhive-config', $candidates[0]);
        self::assertContains('/xdg/arkhive/config', $candidates);
        self::assertContains('/home/env/.arkhive-config', $candidates);
    }

    public function testFromEnvironmentFallsBackToThePasswdHome(): void
    {
        $user = posix_getpwuid(posix_geteuid());
        self::assertIsArray($user);

        self::assertContains(rtrim((string) $user['dir'], '/') . '/.arkhive-config', ConfigLocator::fromEnvironment([])->candidates());
    }
}
