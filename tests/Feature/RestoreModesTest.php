<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\Cli;
use Tests\Support\RequiresRestic;
use Tests\Support\TempDir;

/**
 * The real entry point and the real restic against a local repository: the wizard under a
 * pseudo-TTY taking only defaults, and unattended runs that never prompt (spec §10.1, §10.2, §11).
 */
#[Group('process')]
final class RestoreModesTest extends TestCase
{
    use RequiresRestic;
    use TempDir;

    private string $dir;
    private string $data;
    /** @var array<string, string> */
    private array $env;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->data = "{$this->dir}/data";
        mkdir("{$this->data}/site/css", 0755, true);
        file_put_contents("{$this->data}/site/index.php", "<?php echo 'restored';\n");
        file_put_contents("{$this->data}/site/css/app.css", str_repeat("body{}\n", 500));
        file_put_contents("{$this->dir}/password", "test-password-placeholder\n");
        chmod("{$this->dir}/password", 0600);
        $this->env = ['RESTIC_REPOSITORY' => "{$this->dir}/repo", 'RESTIC_PASSWORD_FILE' => "{$this->dir}/password", 'RESTIC_CACHE_DIR' => "{$this->dir}/cache"];
        [$exit, , $stderr] = self::restic(['init'], $this->env);
        self::assertSame(0, $exit, $stderr);
        [$exit, , $stderr] = self::restic(['backup', '--host', 'web1.example.com', '--tag', 'arkhive', $this->data], $this->env);
        self::assertSame(0, $exit, $stderr);
    }

    /**
     * @return list<string>
     */
    private function repoFlags(): array
    {
        return ['--repo', "{$this->dir}/repo", '--password-file', "{$this->dir}/password"];
    }

    /**
     * @return array<string, string>
     */
    private function cliEnv(): array
    {
        return [
            'PATH' => dirname(self::resticBinary()) . ':' . (string) getenv('PATH'),
            'HOME' => "{$this->dir}/home",
            'RESTIC_CACHE_DIR' => "{$this->dir}/cache",
            'ARKHIVE_LOCK_DIR' => $this->dir,
        ];
    }

    public function testWizardWithOnlyEnterRestoresTheLatestSnapshotToANewDirectory(): void
    {
        mkdir("{$this->dir}/cwd");

        $result = Cli::run(['restore', ...$this->repoFlags()], $this->cliEnv(), pty: true, stdin: str_repeat("\n", 8), cwd: "{$this->dir}/cwd");

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertStringContainsString('Snapshot (number or id) [1]:', $result->stdout);
        self::assertStringContainsString('What do you want to restore?', $result->stdout);
        self::assertStringContainsString('Start the restore? [Y/n]:', $result->stdout);
        self::assertStringContainsString('Restored and verified', $result->stdout);
        $restored = glob("{$this->dir}/cwd/arkhive-restore-web1.example.com-*-*-*") ?: [];
        self::assertCount(1, $restored, 'the default target is a new directory in the current directory');
        self::assertMatchesRegularExpression('/arkhive-restore-web1\.example\.com-[0-9a-f]{8}-\d{8}-\d{6}$/', $restored[0]);
        self::assertSame(hash_file('sha256', "{$this->data}/site/index.php"), hash_file('sha256', $restored[0] . $this->data . '/site/index.php'));
        self::assertSame(hash_file('sha256', "{$this->data}/site/css/app.css"), hash_file('sha256', $restored[0] . $this->data . '/site/css/app.css'));
    }

    public function testUnattendedWithoutSnapshotOrTargetIsExit2AndNeverPrompts(): void
    {
        foreach ([[[], '--snapshot'], [['--snapshot', 'latest'], '--target']] as [$args, $flag]) {
            $result = Cli::run(['restore', ...$this->repoFlags(), ...$args], $this->cliEnv());

            self::assertSame(ExitCode::USAGE, $result->exitCode, $result->output());
            self::assertStringContainsString("is required in unattended mode: pass {$flag}", $result->stderr);
            self::assertStringNotContainsString('[Y/n]', $result->output());
            self::assertStringNotContainsString('Choice [', $result->output());
            self::assertStringNotContainsString("\033[", $result->output());
            self::assertSame('', $result->stdout);
        }
    }

    public function testNoInteractionUnderAPseudoTerminalNeverPrompts(): void
    {
        $result = Cli::run(['restore', '-n', ...$this->repoFlags()], $this->cliEnv(), pty: true);

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->output());
        self::assertStringContainsString('pass --snapshot', $result->stdout);
        self::assertStringNotContainsString('Choice [', $result->stdout);
    }

    public function testUnattendedRestoreWithFlagsAndJson(): void
    {
        $target = "{$this->dir}/out";
        $result = Cli::run(['restore', ...$this->repoFlags(), '--snapshot', 'latest', '--include', "{$this->data}/site/index.php", '--target', $target, '--yes', '--json'], $this->cliEnv());

        self::assertSame(0, $result->exitCode, $result->output());
        $json = json_decode($result->stdout, true);
        self::assertIsArray($json, $result->stdout);
        self::assertTrue($json['verified']);
        self::assertSame($target, $json['target']);
        self::assertFileExists("{$target}{$this->data}/site/index.php");
        self::assertFileDoesNotExist("{$target}{$this->data}/site/css/app.css");
        self::assertStringContainsString(' success Restored and verified ', $result->stderr);
    }

    public function testToStdoutIsByteIdentical(): void
    {
        $result = Cli::run(['restore', ...$this->repoFlags(), '--snapshot', 'latest', '--to-stdout', "{$this->data}/site/css/app.css"], $this->cliEnv());

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertSame(hash_file('sha256', "{$this->data}/site/css/app.css"), hash('sha256', $result->stdout));
    }

    public function testLsAndFindAgainstTheRealRepository(): void
    {
        $ls = Cli::run(['ls', 'latest', "{$this->data}/site", ...$this->repoFlags()], $this->cliEnv());
        self::assertSame(0, $ls->exitCode, $ls->output());
        self::assertMatchesRegularExpression('#-rw-r--r--\s+23 B\s+\S+ \S+\s+' . preg_quote("{$this->data}/site/index.php", '#') . '#', $ls->stdout);

        $find = Cli::run(['find', 'app.css', ...$this->repoFlags(), '--json'], $this->cliEnv());
        self::assertSame(0, $find->exitCode, $find->output());
        $json = json_decode($find->stdout, true);
        self::assertIsArray($json);
        self::assertSame("{$this->data}/site/css/app.css", $json[0]['path']);
        self::assertSame(3500, $json[0]['size']);
    }
}
