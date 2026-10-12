<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Commands\InitCommand;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Process\Command;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

final class InitCommandTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private const EXISTS = '{"message_type":"exit_error","code":1,"message":"Fatal: Fatal: create repository at /srv/restic-repo failed: config file already exists"}';

    private FakeRestic $restic;
    private string $dir;

    /** @var list<string> password file content seen by restic init */
    private array $seenPasswords = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        $runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->install($runner);
        $this->restic->on['init'] = function (array $args, Command $command): array {
            $this->seenPasswords[] = (string) @file_get_contents((string) $command->env['RESTIC_PASSWORD_FILE']);

            return [0, '{"message_type":"initialized","id":"ee41a6504385d9d7e770eff36c1845d1b6ae457ffa7ec3452445d6832b025c00","repository":"/srv/restic-repo"}' . "\n", ''];
        };
        $this->app->instance(ProcessRunner::class, $runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
    }

    public function testCreatesTheRepositoryWithTheExistingPasswordAndRemindsToKeepACopy(): void
    {
        $config = $this->writeConfig();

        self::assertSame(ExitCode::SUCCESS, Artisan::call('init', ['--config' => $config, '--json' => true]));

        self::assertSame(['init', '--json'], $this->restic->callsOf('init')[0]);
        self::assertSame(["repo-password-placeholder\n"], $this->seenPasswords);
        $log = $this->logOutput();
        self::assertStringContainsString('success Created the restic repository /srv/restic-repo (id ee41a650)', $log);
        self::assertStringContainsString("notice Keep a second copy of the password in {$this->dir}/password off this host (a password manager, a safe): without it nobody can restore these backups", $log);
        self::assertStringNotContainsString('Generated', $log);
        self::assertSame(['repository' => '/srv/restic-repo', 'id' => 'ee41a6504385d9d7e770eff36c1845d1b6ae457ffa7ec3452445d6832b025c00', 'created' => true, 'password_generated' => false], json_decode(Artisan::output(), true));
    }

    public function testGeneratesAMissingPasswordFile(): void
    {
        $config = $this->writeConfig(['RESTIC_PASSWORD_FILE' => "{$this->dir}/new-password"]);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('init', ['--config' => $config, '--json' => true]));

        $file = "{$this->dir}/new-password";
        clearstatcache();
        self::assertSame(0600, fileperms($file) & 0777);
        $password = (string) file_get_contents($file);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}\n$/', $password);
        self::assertSame([$password], $this->seenPasswords, 'restic used the new file');
        self::assertStringContainsString("notice Generated a random repository password in {$file} (mode 0600)", $this->logOutput());
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertTrue($json['password_generated']);
    }

    public function testEveryGeneratedPasswordIsDifferent(): void
    {
        InitCommand::generatePassword("{$this->dir}/a");
        InitCommand::generatePassword("{$this->dir}/b");

        self::assertNotSame(file_get_contents("{$this->dir}/a"), file_get_contents("{$this->dir}/b"));
    }

    public function testAPasswordFileInAMissingDirectoryIsAUsageError(): void
    {
        $config = $this->writeConfig(['RESTIC_PASSWORD_FILE' => "{$this->dir}/nope/password"]);

        self::assertSame(ExitCode::USAGE, Artisan::call('init', ['--config' => $config]));
        self::assertStringContainsString("error RESTIC_PASSWORD_FILE {$this->dir}/nope/password does not exist and neither does its directory", $this->logOutput());
        self::assertStringContainsString("notice Create the directory (mkdir -p {$this->dir}/nope), then run arkhive init again", $this->logOutput());
        self::assertSame([], $this->restic->calls);
    }

    public function testAnUnsafePasswordFileIsRefused(): void
    {
        $config = $this->writeConfig();
        chmod("{$this->dir}/password", 0640);

        self::assertSame(ExitCode::USAGE, Artisan::call('init', ['--config' => $config]));
        self::assertStringContainsString("error RESTIC_PASSWORD_FILE {$this->dir}/password is readable by group or others (mode 0640)", $this->logOutput());
        self::assertSame([], $this->restic->calls);
    }

    public function testAnExistingRepositoryThatThePasswordOpensIsNothingToDo(): void
    {
        $this->restic->on['init'] = static fn (): array => [1, '', self::EXISTS . "\n"];

        self::assertSame(ExitCode::SUCCESS, Artisan::call('init', ['--config' => $this->writeConfig(), '--json' => true]));

        self::assertSame(['cat', 'config'], $this->restic->calls[2]);
        self::assertStringContainsString('success The repository /srv/restic-repo already exists and the password opens it: nothing to do', $this->logOutput());
        self::assertSame(['repository' => '/srv/restic-repo', 'id' => null, 'created' => false, 'password_generated' => false], json_decode(Artisan::output(), true));
    }

    public function testAnExistingRepositoryWithAnotherPasswordIsExit1(): void
    {
        $this->restic->on['init'] = static fn (): array => [1, '', self::EXISTS . "\n"];
        $this->restic->catConfig = [12, '', FakeRestic::fixture('wrong-password-0.19.stderr')];

        self::assertSame(ExitCode::FAILURE, Artisan::call('init', ['--config' => $this->writeConfig()]));
        self::assertStringContainsString('error Wrong password for the restic repository /srv/restic-repo', $this->logOutput());
    }

    public function testAnExistingRepositoryRemovesTheUselessGeneratedPassword(): void
    {
        $this->restic->on['init'] = static fn (): array => [1, '', self::EXISTS . "\n"];
        $config = $this->writeConfig(['RESTIC_PASSWORD_FILE' => "{$this->dir}/new-password"]);

        self::assertSame(ExitCode::FAILURE, Artisan::call('init', ['--config' => $config]));

        self::assertFileDoesNotExist("{$this->dir}/new-password");
        self::assertStringContainsString("error A restic repository already exists at /srv/restic-repo, and RESTIC_PASSWORD_FILE {$this->dir}/new-password did not exist", $this->logOutput());
        self::assertStringContainsString("notice Put that repository's password in {$this->dir}/new-password (chmod 600); a new password cannot open it", $this->logOutput());
    }

    public function testAFailedInitRemovesTheGeneratedPassword(): void
    {
        $this->restic->on['init'] = static fn (): array => [1, '', "Fatal: create repository at /srv/restic-repo failed: mkdir /srv/restic-repo: permission denied\n"];
        $config = $this->writeConfig(['RESTIC_PASSWORD_FILE' => "{$this->dir}/new-password"]);

        self::assertSame(ExitCode::FAILURE, Artisan::call('init', ['--config' => $config]));

        self::assertFileDoesNotExist("{$this->dir}/new-password");
        self::assertStringContainsString('error Creating the restic repository failed (restic exit 1): Fatal: create repository at /srv/restic-repo failed: mkdir /srv/restic-repo: permission denied', $this->logOutput());
    }
}
