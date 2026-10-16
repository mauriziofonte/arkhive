<?php

declare(strict_types=1);

namespace Tests\e2e;

use Mfonte\Arkhive\ExitCode;
use Tests\Support\LegacyArchives;

/**
 * spec §13.2 step 12: decrypt-legacy, run from the PHAR, on archives produced with the 1.x command
 * line (absolute paths in the tar list, as 1.x wrote them) round-trips byte-identical.
 */
final class LegacyTest extends E2eTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/host");
        LegacyArchives::tree("{$this->dir}/host");
        file_put_contents("{$this->dir}/password", LegacyArchives::PASSWORD . "\n");
        chmod("{$this->dir}/password", 0600);
    }

    public function testEveryOneXFormatRoundTripsByteIdentical(): void
    {
        $original = LegacyArchives::manifest("{$this->dir}/host/srv");
        foreach (['gzip', 'xz', 'none'] as $compression) {
            foreach ([true, false] as $encrypted) {
                $label = $compression . ($encrypted ? ' encrypted' : '');
                $made = "{$this->dir}/{$compression}-" . ($encrypted ? 'enc' : 'plain');
                mkdir($made);
                $archive = LegacyArchives::make('/', $made, $compression, $encrypted, list: ["{$this->dir}/host/srv"]);
                $to = "{$made}/out";

                $result = self::arkhive(['decrypt-legacy', $archive, '--to', $to, '--password-file', "{$this->dir}/password", '--json']);

                self::assertSame(ExitCode::SUCCESS, $result->exitCode, "{$label}: {$result->stderr}");
                self::assertSame($original, LegacyArchives::manifest($to . "{$this->dir}/host/srv"), $label);
                $json = json_decode($result->stdout, true);
                self::assertIsArray($json, $label);
                self::assertSame([$encrypted, $compression], [$json['encrypted'], $json['compression']], $label);
                self::assertStringNotContainsString("\e[", $result->stderr, 'no colour without a terminal');
            }
        }
    }

    public function testThePasswordFromTheEnvironmentAndADefaultDirectory(): void
    {
        $archive = LegacyArchives::make("{$this->dir}/host", $this->dir, 'gzip', true);
        mkdir("{$this->dir}/cwd");

        $result = self::arkhive(['decrypt-legacy', $archive], ['ARKHIVE_CRYPT_PASSWORD' => LegacyArchives::PASSWORD], "{$this->dir}/cwd");

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->stderr);
        self::assertSame(LegacyArchives::manifest("{$this->dir}/host"), LegacyArchives::manifest("{$this->dir}/cwd/2024-05-01-web-arkhive"));
    }

    public function testWithoutAPasswordNeitherModePrompts(): void
    {
        $archive = LegacyArchives::make("{$this->dir}/host", $this->dir, 'gzip', true);

        $unattended = self::arkhive(['decrypt-legacy', $archive, '--to', "{$this->dir}/out"]);
        self::assertSame(ExitCode::USAGE, $unattended->exitCode);
        self::assertStringContainsString('pass --password-file <file> or set ARKHIVE_CRYPT_PASSWORD', $unattended->stderr);

        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, self::env('E2E_PHAR'), 'decrypt-legacy', $archive, '--to', "{$this->dir}/out"]));
        $tty = self::exec(['script', '-qec', $command . '; echo "exit=$?"', '/dev/null'], ['TERM' => 'xterm']);
        self::assertStringContainsString('pass --password-file <file> or set ARKHIVE_CRYPT_PASSWORD', $tty->stdout);
        self::assertStringContainsString('exit=64', $tty->stdout);
        self::assertDirectoryDoesNotExist("{$this->dir}/out");
    }

    public function testAWrongPasswordIsExplained(): void
    {
        $archive = LegacyArchives::make("{$this->dir}/host", $this->dir, 'xz', true);

        $result = self::arkhive(['decrypt-legacy', $archive, '--to', "{$this->dir}/out"], ['ARKHIVE_CRYPT_PASSWORD' => 'not it']);

        self::assertSame(ExitCode::NO_PERMISSION, $result->exitCode);
        self::assertStringContainsString('the password is wrong', $result->stderr);
        self::assertDirectoryDoesNotExist("{$this->dir}/out");
        self::assertSame([], glob("{$this->dir}/.arkhive-legacy-*") ?: []);
    }
}
