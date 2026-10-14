<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Tests\Support\CliResult;
use Tests\Support\TempDir;

/**
 * .github/scripts/changelog-section.php: the release notes of release.yml.
 */
final class ChangelogSectionTest extends TestCase
{
    use TempDir;

    private const SCRIPT = __DIR__ . '/../../../.github/scripts/changelog-section.php';

    private const CHANGELOG = <<<'MD'
        # Changelog

        All notable changes to this project are documented in this file.

        ## [Unreleased]

        - Not yet released.

        ## [2.0.0-rc.1] - 2026-10-14

        ### Added

        - restic backend.

        ### Removed

        - 1.x archive format.

        ## [1.7.1] - 2025-01-01

        - Fix.

        ## [1.7.0] - 2024-12-01

        [2.0.0-rc.1]: https://github.com/mauriziofonte/arkhive/compare/v1.7.1...v2.0.0-rc.1
        [1.7.1]: https://github.com/mauriziofonte/arkhive/releases/tag/v1.7.1
        MD;

    public function testTheSectionOfAVersion(): void
    {
        $result = $this->section('2.0.0-rc.1');

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame("### Added\n\n- restic backend.\n\n### Removed\n\n- 1.x archive format.\n", $result->stdout);
    }

    public function testTheLastSectionStopsAtTheLinkReferences(): void
    {
        $result = $this->section('1.7.1');

        self::assertSame("- Fix.\n", $result->stdout);
    }

    public function testAMissingSectionFails(): void
    {
        $result = $this->section('2.0.0');

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringEndsWith("has no section \"## [2.0.0]\"\n", $result->stderr);
    }

    public function testAnEmptySectionFails(): void
    {
        $result = $this->section('1.7.0');

        self::assertSame(1, $result->exitCode);
        self::assertStringEndsWith("has no non-empty section \"## [1.7.0]\"\n", $result->stderr);
    }

    public function testUsage(): void
    {
        self::assertSame(2, self::script([PHP_BINARY, self::SCRIPT])->exitCode);
        self::assertSame(1, self::script([PHP_BINARY, self::SCRIPT, '/nonexistent/CHANGELOG.md', '1.0.0'])->exitCode);
    }

    private function section(string $version): CliResult
    {
        $file = $this->tempDir() . '/CHANGELOG.md';
        file_put_contents($file, self::CHANGELOG . "\n");

        return self::script([PHP_BINARY, self::SCRIPT, $file, $version]);
    }

    /**
     * @param list<string> $argv
     */
    private static function script(array $argv): CliResult
    {
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new CliResult(proc_close($process), $stdout, $stderr);
    }
}
