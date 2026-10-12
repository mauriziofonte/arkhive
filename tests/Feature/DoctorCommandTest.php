<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

final class DoctorCommandTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private FakeRestic $restic;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/locks");
        $this->terminal->env = ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'HOME' => "{$this->dir}/home"];
        $runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->withRepository()->install($runner);
        $this->app->instance(ProcessRunner::class, $runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic', 'ssh']));
        $this->clock->now = new DateTimeImmutable('2026-10-12 20:17:53', new DateTimeZone('UTC'));
    }

    public function testAHealthyHostIsExit0(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('doctor', ['--config' => $this->writeConfig()]));

        $lines = explode("\n", rtrim(Artisan::output()));
        self::assertMatchesRegularExpression('/^ok    PHP                PHP 8\.\d+\.\d+ with phar, json, mbstring, posix, pcntl/', $lines[0]);
        self::assertSame('ok    distribution       debian 12, packages via apt install <package>', $lines[1]);
        self::assertSame("ok    restic             restic 0.19.1 at {$this->dir}/bin/restic", $lines[2]);
        self::assertSame("ok    configuration      {$this->dir}/config", $lines[3]);
        self::assertSame('ok    latest snapshot    e82340a7 of web1.example.com at 2026-10-12 14:17 UTC (6 h ago)', $lines[8]);
        self::assertSame('info  execution context  unattended (no terminal), log format plain', $lines[11]);
        self::assertStringContainsString('success Doctor: everything looks fine', $this->logOutput());
    }

    public function testWarningsOnlyAreExit3AndHintsArePrinted(): void
    {
        $this->clock->now = new DateTimeImmutable('2026-10-15 08:00:00', new DateTimeZone('UTC'));

        self::assertSame(ExitCode::WARNING, Artisan::call('doctor', ['--config' => $this->writeConfig()]));

        self::assertStringContainsString(
            "WARN  latest snapshot    e82340a7 of web1.example.com at 2026-10-12 14:17 UTC (2 d ago): older than 26 hours\n"
            . '                         hint: Check that the backup timer or cron job runs',
            Artisan::output(),
        );
        self::assertStringContainsString('warning Doctor: 0 failed, 1 warning(s), 13 checks', $this->logOutput());
    }

    public function testAFailureIsExit1(): void
    {
        $config = $this->writeConfig();
        chmod("{$this->dir}/password", 0644);

        self::assertSame(ExitCode::FAILURE, Artisan::call('doctor', ['--config' => $config]));

        $output = Artisan::output();
        self::assertStringContainsString("FAIL  password file      RESTIC_PASSWORD_FILE {$this->dir}/password is readable by group or others (mode 0644)\n                         hint: Run: chmod 600 {$this->dir}/password\n", $output);
        self::assertStringContainsString('skip  repository         needs restic and a valid password file', $output);
        self::assertStringContainsString('error Doctor: 1 failed, 0 warning(s), 13 checks', $this->logOutput());
    }

    public function testMultiLineMessagesAreIndented(): void
    {
        self::assertSame(ExitCode::FAILURE, Artisan::call('doctor', ['--config' => $this->writeConfig(['SSH_HOST' => 'backup.example.com'])]));

        self::assertStringContainsString("FAIL  removed 1.x keys   The configuration {$this->dir}/config uses keys removed in ArkHive 2.0:\n                           SSH_HOST: replaced by", Artisan::output());
    }

    public function testJson(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('doctor', ['--config' => $this->writeConfig(), '--json' => true]));

        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertSame('ok', $json['status']);
        self::assertCount(13, $json['checks']);
        self::assertSame(['check' => 'repository', 'status' => 'ok', 'message' => '/srv/restic-repo is reachable, the password opens it, no lock', 'hint' => null], $json['checks'][7]);
    }

    public function testBareMetalWithRelativePasswordFile(): void
    {
        file_put_contents("{$this->dir}/pw", "placeholder\n");
        chmod("{$this->dir}/pw", 0600);
        $cwd = getcwd();
        chdir($this->dir);
        try {
            self::assertSame(ExitCode::SUCCESS, Artisan::call('doctor', ['--repo' => '/srv/restic-repo', '--password-file' => 'pw', '--json' => true]));
        } finally {
            chdir((string) $cwd);
        }
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertSame('info', $json['checks'][3]['status']);
        self::assertSame("{$this->dir}/pw is private (mode 0600)", $json['checks'][5]['message']);
    }
}
