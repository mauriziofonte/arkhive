<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\ExitCode;
use Tests\Support\LegacyArchives;
use Tests\Support\TempDir;
use Tests\TestCase;

/**
 * spec §6 decrypt-legacy: where the password comes from, the default directory, JSON and exits.
 */
final class DecryptLegacyCommandTest extends TestCase
{
    use TempDir;

    private string $dir;
    private string $archive;
    private string $cwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/src");
        LegacyArchives::tree("{$this->dir}/src");
        $this->archive = LegacyArchives::make("{$this->dir}/src", $this->dir, 'gzip', true);
        $this->cwd = (string) getcwd();
        mkdir("{$this->dir}/cwd");
        chdir("{$this->dir}/cwd");
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        parent::tearDown();
    }

    public function testPasswordFileAndJson(): void
    {
        file_put_contents("{$this->dir}/pw", LegacyArchives::PASSWORD . "\n");
        chmod("{$this->dir}/pw", 0600);

        $exit = Artisan::call('decrypt-legacy', ['file' => $this->archive, '--to' => '../restored', '--password-file' => "{$this->dir}/pw", '--json' => true]);

        self::assertSame(ExitCode::SUCCESS, $exit, $this->logOutput());
        self::assertSame(LegacyArchives::manifest("{$this->dir}/src"), LegacyArchives::manifest("{$this->dir}/restored"));
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertGreaterThan(200000, $json['bytes']);
        unset($json['bytes']);
        self::assertSame(['archive' => $this->archive, 'target' => "{$this->dir}/restored", 'encrypted' => true, 'compression' => 'gzip', 'entries' => 10], $json);
        self::assertMatchesRegularExpression('/success Extracted 10 entries \(.+, gzip, decrypted\) into ' . preg_quote("{$this->dir}/restored", '/') . '/', $this->logOutput());
    }

    public function testEnvironmentPasswordAndDefaultDirectory(): void
    {
        $this->terminal->env['ARKHIVE_CRYPT_PASSWORD'] = LegacyArchives::PASSWORD;

        self::assertSame(ExitCode::SUCCESS, Artisan::call('decrypt-legacy', ['file' => $this->archive]), $this->logOutput());
        self::assertFileExists("{$this->dir}/cwd/2024-05-01-web-arkhive/srv/www/site/index.php");

        self::assertSame(ExitCode::CANNOT_CREATE, Artisan::call('decrypt-legacy', ['file' => $this->archive]));
        self::assertStringContainsString("{$this->dir}/cwd/2024-05-01-web-arkhive exists and is not an empty directory", $this->logOutput());
    }

    public function testThePasswordFileWinsOverTheEnvironment(): void
    {
        $this->terminal->env['ARKHIVE_CRYPT_PASSWORD'] = 'wrong';
        file_put_contents("{$this->dir}/pw", LegacyArchives::PASSWORD);
        chmod("{$this->dir}/pw", 0600);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('decrypt-legacy', ['file' => $this->archive, '--password-file' => "{$this->dir}/pw", '--to' => "{$this->dir}/out"]), $this->logOutput());
    }

    public function testWithoutAPasswordItIsAUsageErrorNamingTheFlag(): void
    {
        self::assertSame(ExitCode::USAGE, Artisan::call('decrypt-legacy', ['file' => $this->archive]));
        self::assertStringContainsString('error The archive is encrypted and no password was given: pass --password-file <file> or set ARKHIVE_CRYPT_PASSWORD', $this->logOutput());
        self::assertDirectoryDoesNotExist("{$this->dir}/cwd/2024-05-01-web-arkhive");
    }

    public function testAPasswordFileReadableByOthersIsRefused(): void
    {
        file_put_contents("{$this->dir}/pw", LegacyArchives::PASSWORD);
        chmod("{$this->dir}/pw", 0644);
        self::assertSame(ExitCode::NO_PERMISSION, Artisan::call('decrypt-legacy', ['file' => $this->archive, '--password-file' => "{$this->dir}/pw"]));
        self::assertStringContainsString("--password-file {$this->dir}/pw is readable by group or others (mode 0644)", $this->logOutput());

        file_put_contents("{$this->dir}/empty", "\n");
        chmod("{$this->dir}/empty", 0600);
        self::assertSame(ExitCode::USAGE, Artisan::call('decrypt-legacy', ['file' => $this->archive, '--password-file' => "{$this->dir}/empty"]));
        self::assertStringContainsString("--password-file {$this->dir}/empty holds an empty password", $this->logOutput());
    }

    public function testWrongPasswordFails(): void
    {
        $this->terminal->env['ARKHIVE_CRYPT_PASSWORD'] = 'wrong';

        self::assertSame(ExitCode::NO_PERMISSION, Artisan::call('decrypt-legacy', ['file' => $this->archive, '--to' => "{$this->dir}/out"]));
        self::assertStringContainsString('error Cannot decrypt the archive: the password is wrong', $this->logOutput());
        self::assertDirectoryDoesNotExist("{$this->dir}/out");
    }

    public function testAnUnencryptedArchiveNeedsNoPassword(): void
    {
        $plain = LegacyArchives::make("{$this->dir}/src", $this->dir, 'xz', false);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('decrypt-legacy', ['file' => $plain, '--to' => "{$this->dir}/out"]), $this->logOutput());
        self::assertStringContainsString('notice 2024-05-01-web-arkhive.arbk.xz is not encrypted: extracting it as it is', $this->logOutput());
        self::assertFileExists("{$this->dir}/out/srv/www/site/index.php");
    }

    public function testAMissingArchiveIsAUsageError(): void
    {
        self::assertSame(ExitCode::NO_INPUT, Artisan::call('decrypt-legacy', ['file' => 'nothing.enc.arbk']));
        self::assertStringContainsString("error The archive {$this->dir}/cwd/nothing.enc.arbk does not exist", $this->logOutput());
    }
}
