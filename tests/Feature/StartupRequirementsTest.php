<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\Cli;

/**
 * The entry point refuses to start, with the install command, when PHP lacks a required extension.
 * `php -n` (no php.ini) unloads every shared extension, which is a real missing-extension host.
 */
final class StartupRequirementsTest extends TestCase
{
    public function testMissingExtensionsStopTheEntryPointWithTheInstallCommand(): void
    {
        $requirements = dirname(__DIR__, 2) . '/bootstrap/requirements.php';
        $expected = shell_exec(implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, '-n', '-r', 'require ' . var_export($requirements, true) . '; echo implode(PHP_EOL, arkhive_startup_problems());',
        ])));
        if (!is_string($expected) || $expected === '') {
            self::markTestSkipped('this PHP has every required extension compiled in');
        }

        $result = Cli::run(['--version'], phpOptions: ['-n']);

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame($expected . "\n", $result->stderr);
        self::assertStringStartsWith('ArkHive needs the PHP extension', $result->stderr);
    }

    public function testACompletePhpStartsNormally(): void
    {
        $result = Cli::run(['--version']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringStartsWith('ArkHive ', $result->stdout);
    }
}
