<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\Prompter;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\ExitCode;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakePrompter;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\TestCase;

/**
 * The restore command in-process: flags, exit codes, bare-metal options, and the wizard with
 * scripted answers (the real terminal is covered by RestoreModesTest).
 */
final class RestoreCommandTest extends TestCase
{
    use TempDir;

    private FakeProcessRunner $runner;
    private FakeRestic $restic;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/locks");
        $this->terminal->env = ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'HOME' => "{$this->dir}/home"];
        $this->runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->withRepository()->install($this->runner);
        $this->app->instance(ProcessRunner::class, $this->runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
        file_put_contents("{$this->dir}/password", "repo-password-placeholder\n");
        chmod("{$this->dir}/password", 0600);
    }

    private function writeConfig(): string
    {
        file_put_contents("{$this->dir}/config", implode("\n", [
            'BACKUP_PATHS="/srv/www"',
            'RESTIC_REPOSITORY="/srv/restic-repo"',
            "RESTIC_PASSWORD_FILE=\"{$this->dir}/password\"",
            'RESTIC_HOST="web1.example.com"',
        ]) . "\n");

        return "{$this->dir}/config";
    }

    /**
     * @param array<string, mixed> $args
     */
    private function restore(array $args): int
    {
        return Artisan::call('restore', $args);
    }

    public function testUnattendedRestoreWithFlags(): void
    {
        $target = "{$this->dir}/out";

        $exit = $this->restore(['--config' => $this->writeConfig(), '--snapshot' => 'latest', '--host' => 'web1.example.com', '--include' => ['/srv/www/site'], '--target' => $target, '--yes' => true, '--json' => true]);

        self::assertSame(ExitCode::SUCCESS, $exit, $this->logOutput());
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertSame('e82340a7', substr((string) $json['snapshot'], 0, 8));
        self::assertSame($target, $json['target']);
        self::assertSame(['/srv/www/site'], $json['includes']);
        self::assertTrue($json['verified']);
        self::assertSame(['restore', '--json', '--verify', '--sparse', '--target', $target, '--include', '/srv/www/site', '--', $json['snapshot']], $this->restic->callsOf('restore')[0]);
        self::assertStringContainsString('info Opening the repository /srv/restic-repo', $this->logOutput());
        self::assertStringNotContainsString("\033[", $this->logOutput());
    }

    public function testUnattendedMissingAnswersAreExit2NamingTheFlag(): void
    {
        $config = $this->writeConfig();
        foreach ([
            [[], '--snapshot'],
            [['--snapshot' => 'latest'], '--target'],
            [['--snapshot' => 'latest', '--target' => "{$this->dir}/out"], '--yes'],
        ] as [$args, $flag]) {
            $this->logStream = \Mfonte\Arkhive\Infrastructure\Logging\LogStream::memory();
            $this->app->instance(\Mfonte\Arkhive\Infrastructure\Logging\LogStream::class, $this->logStream);

            self::assertSame(ExitCode::USAGE, $this->restore(['--config' => $config, ...$args]));
            self::assertStringContainsString("is required in unattended mode: pass {$flag}", $this->logOutput());
        }
        self::assertSame([], $this->restic->callsOf('restore'));
        self::assertFileDoesNotExist("{$this->dir}/out");
    }

    public function testBareMetalWithoutAnyConfiguration(): void
    {
        $exit = $this->restore(['--repo' => 'sftp:backup@backup.example.com:/restic', '--password-file' => "{$this->dir}/password", '--sftp-command' => 'ssh -p 2222 backup@backup.example.com -s sftp', '--snapshot' => '6118', '--target' => "{$this->dir}/out", '--yes' => true]);

        self::assertSame(ExitCode::SUCCESS, $exit, $this->logOutput());
        self::assertSame(['version'], array_slice($this->runner->commands[0]->argv, 1));
        $command = $this->runner->commands[1];
        self::assertSame('sftp:backup@backup.example.com:/restic', $command->env['RESTIC_REPOSITORY']);
        self::assertSame("{$this->dir}/password", $command->env['RESTIC_PASSWORD_FILE']);
        self::assertContains('sftp.command=ssh -p 2222 backup@backup.example.com -s sftp', $command->argv);
        self::assertFileExists("{$this->dir}/out/srv/www/site/index.php");
    }

    public function testBareMetalOptionErrors(): void
    {
        chmod("{$this->dir}/password", 0644);
        foreach ([
            [['--repo' => '/srv/restic-repo'], '--repo needs --password-file', ExitCode::USAGE],
            [['--password-file' => "{$this->dir}/password"], '--password-file goes with --repo', ExitCode::USAGE],
            [['--sftp-command' => 'ssh x'], '--sftp-command goes with --repo', ExitCode::USAGE],
            [['--repo' => '/srv/restic-repo', '--password-file' => "{$this->dir}/password"], "--password-file {$this->dir}/password is readable by group or others (mode 0644)", ExitCode::NO_PERMISSION],
            [['--repo' => '/srv/restic-repo', '--password-file' => "{$this->dir}/missing"], "--password-file {$this->dir}/missing does not exist", ExitCode::NO_INPUT],
        ] as [$args, $message, $exit]) {
            $this->logStream = \Mfonte\Arkhive\Infrastructure\Logging\LogStream::memory();
            $this->app->instance(\Mfonte\Arkhive\Infrastructure\Logging\LogStream::class, $this->logStream);

            self::assertSame($exit, $this->restore($args), $message);
            self::assertStringContainsString($message, $this->logOutput());
        }
        self::assertSame([], $this->runner->commands, 'restic never ran');
    }

    public function testThe1xDestinationArgumentPointsToTarget(): void
    {
        self::assertSame(ExitCode::USAGE, $this->restore(['destination' => '/var/restore']));
        self::assertStringContainsString('The 1.x destination argument is now --target: arkhive restore --target /var/restore', $this->logOutput());
    }

    public function testConflictingFlags(): void
    {
        self::assertSame(ExitCode::USAGE, $this->restore(['--snapshot' => 'latest', '--to-stdout' => '/etc/hosts', '--json' => true]));
        self::assertStringContainsString('--json cannot be combined with --to-stdout', $this->logOutput());
        self::assertSame(ExitCode::USAGE, $this->restore(['--snapshot' => 'latest', '--in-place' => true, '--target' => '/x']));
        self::assertStringContainsString('--in-place cannot be combined with --target', $this->logOutput());
    }

    public function testToStdoutCarriesOnlyTheFileBytes(): void
    {
        $exit = $this->restore(['--config' => $this->writeConfig(), '--snapshot' => '6118', '--to-stdout' => '/srv/www/site/index.php']);

        self::assertSame(ExitCode::SUCCESS, $exit, $this->logOutput());
        self::assertSame("dump of /srv/www/site/index.php\n", Artisan::output());
        self::assertStringContainsString('info Writing /srv/www/site/index.php (22 B) from snapshot 6118b3c5 to standard output', $this->logOutput());
    }

    public function testANonEmptyTargetIsExit4AndNothingIsWritten(): void
    {
        mkdir("{$this->dir}/full");
        file_put_contents("{$this->dir}/full/keep", 'mine');

        self::assertSame(ExitCode::CANNOT_CREATE, $this->restore(['--config' => $this->writeConfig(), '--snapshot' => '6118', '--target' => "{$this->dir}/full", '--yes' => true]));
        self::assertStringContainsString('is not empty: ArkHive restores only into a new or empty directory', $this->logOutput());
        self::assertSame(['keep'], array_values(array_diff(scandir("{$this->dir}/full") ?: [], ['.', '..'])));
        self::assertSame([], $this->restic->callsOf('restore'));

        self::assertSame(ExitCode::SUCCESS, $this->restore(['--config' => $this->writeConfig(), '--snapshot' => '6118', '--target' => "{$this->dir}/full", '--yes' => true, '--force' => true]));
    }

    public function testInPlaceWithoutForceIsExit4(): void
    {
        self::assertSame(ExitCode::USAGE, $this->restore(['--config' => $this->writeConfig(), '--snapshot' => '6118', '--in-place' => true, '--yes' => true]));
        self::assertStringContainsString('An in-place restore overwrites the original files and needs --force', $this->logOutput());
        self::assertSame([], $this->restic->callsOf('backup'));
        self::assertSame([], $this->restic->callsOf('restore'));
    }

    public function testMissingRepositoryDataIsExit65WithTheRepairStepsAndNoTargetIsLeft(): void
    {
        // restic stats --mode restore-size on a snapshot whose tree pack was deleted (restic 0.19.1)
        $this->restic->on['stats'] = static fn (): array => [1, '', FakeRestic::fixture('stats-missing-0.19.stderr')];
        $target = "{$this->dir}/out";

        self::assertSame(ExitCode::DATA_ERROR, $this->restore(['--config' => $this->writeConfig(), '--snapshot' => 'latest', '--host' => 'web1.example.com', '--target' => $target, '--yes' => true]));

        $log = $this->logOutput();
        self::assertStringContainsString('error Measuring snapshot e82340a7', $log);
        self::assertStringContainsString('should hold is missing (pack files deleted or lost on the server)', $log);
        self::assertStringContainsString('restic repair index, then arkhive backup', $log);
        self::assertDirectoryDoesNotExist($target);
    }

    public function testDataMissingDuringTheRestoreIsExit65AndThePartialTargetIsRemoved(): void
    {
        $this->restic->on['restore'] = static function (array $args): array {
            $target = $args[(int) array_search('--target', $args, true) + 1];
            mkdir("{$target}/srv/www", 0755, true);
            file_put_contents("{$target}/srv/www/partial", 'x');

            return [1, '', FakeRestic::fixture('restore-missing-0.19.stderr')];
        };
        $target = "{$this->dir}/out";

        self::assertSame(ExitCode::DATA_ERROR, $this->restore(['--config' => $this->writeConfig(), '--snapshot' => 'latest', '--host' => 'web1.example.com', '--include' => ['/srv/www/site'], '--target' => $target, '--yes' => true]));
        self::assertStringContainsString('error restic restore failed: data the repository /srv/restic-repo should hold is missing', $this->logOutput());
        self::assertDirectoryDoesNotExist($target);
    }

    public function testWrongPasswordIsExplainedAndNoTargetIsCreated(): void
    {
        $this->restic->catConfig = [12, '', FakeRestic::fixture('wrong-password-0.19.stderr')];

        self::assertSame(ExitCode::NO_PERMISSION, $this->restore(['--config' => $this->writeConfig(), '--snapshot' => 'latest', '--target' => "{$this->dir}/out", '--yes' => true]));
        self::assertStringContainsString('error Wrong password for the restic repository /srv/restic-repo', $this->logOutput());
        self::assertFileDoesNotExist("{$this->dir}/out");
    }

    public function testAStaleRepositoryLockIsExplainedWithTheUnlockCommand(): void
    {
        $id = str_repeat('c', 64);
        $this->restic->on['list'] = static fn (): array => [0, "{$id}\n", ''];
        $this->restic->on['cat'] = static fn (array $args): array => $args[1] === 'lock' ? [0, FakeRestic::fixture('lock-0.19.json'), ''] : [0, "{}\n", ''];
        $this->clock->now = new \DateTimeImmutable('2026-10-12T18:00:00Z');

        self::assertSame(ExitCode::SUCCESS, $this->restore(['--config' => $this->writeConfig(), '--snapshot' => '6118', '--target' => "{$this->dir}/out", '--yes' => true]));
        self::assertStringContainsString('warning The repository has a stale lock: a lock held by root@host.example.com (PID 30066) since 2026-10-12 14:14:49 Z', $this->logOutput());
        self::assertStringContainsString("notice If no other restic process uses the repository, remove it with: RESTIC_PASSWORD_FILE='{$this->dir}/password' restic -r '/srv/restic-repo' unlock (ArkHive never does this automatically)", $this->logOutput());
        self::assertSame([], $this->restic->callsOf('unlock'));
    }

    public function testTheWizardInATerminal(): void
    {
        $this->terminal->stdinTty = true;
        $this->terminal->stdoutTty = true;
        $prompter = new FakePrompter(['h:web1.example.com', '2', '', "{$this->dir}/wizard", '']);
        $this->app->instance(Prompter::class, $prompter);

        self::assertSame(ExitCode::SUCCESS, $this->restore(['--config' => $this->writeConfig()]), $this->logOutput());
        self::assertFileExists("{$this->dir}/wizard/srv/www/site/index.php");
        self::assertSame('Start the restore?', $prompter->questions[array_key_last($prompter->questions)]);
        self::assertStringContainsString('✅ Restored and verified 14 files and directories (82 B)', $this->logOutput());
    }

    public function testNoInteractionInATerminalNeverPrompts(): void
    {
        $this->terminal->stdinTty = true;
        $this->terminal->stdoutTty = true;
        $prompter = new FakePrompter([]);
        $this->app->instance(Prompter::class, $prompter);

        self::assertSame(ExitCode::USAGE, $this->restore(['--config' => $this->writeConfig(), '--no-interaction' => true]));
        self::assertSame([], $prompter->questions);
        self::assertStringContainsString('pass --snapshot', $this->logOutput());
    }
}
