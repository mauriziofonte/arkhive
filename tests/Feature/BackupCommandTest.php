<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Notify\Notifier;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Tests\Support\CapturingTransport;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

final class BackupCommandTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private FakeProcessRunner $runner;
    private FakeRestic $restic;
    private CapturingTransport $mail;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/locks");
        $this->terminal->env = ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'HOME' => "{$this->dir}/home"];
        $this->runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->install($this->runner);
        $this->mail = new CapturingTransport();
        $this->app->instance(ProcessRunner::class, $this->runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
        $this->app->instance(Notifier::class, new Notifier($this->mail->factory()));
    }

    public function testASuccessfulBackupIsExit0WithASummaryAndANotification(): void
    {
        $config = $this->writeConfig(self::notify());

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $config, '--json' => true]));

        self::assertStringContainsString('success Snapshot e8ecb7f4 saved: 1.24 MiB added (1.22 MiB processed), 51 new, 0 changed, 0 unmodified files in 0.7s', $this->logOutput());
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertSame('e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', $json['snapshot_id']);
        self::assertSame([], $json['dumps']);
        self::assertSame([], $json['warnings']);

        self::assertCount(1, $this->mail->sent);
        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] backup OK on web1.example.com', $mail->getSubject());
        $body = (string) $mail->getTextBody();
        self::assertStringStartsWith("The backup completed successfully.\n\n", $body);
        foreach (['Host:          web1.example.com', "Configuration: {$config}", 'Repository:    /srv/restic-repo', 'Paths:         /srv/www', 'Snapshot:      e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', 'Data added:    1.24 MiB', 'Files:         51 new, 0 changed, 0 unmodified', 'Dumps:         none', 'restic:        0.19.1', 'ArkHive:       ' . config('app.version')] as $line) {
            self::assertStringContainsString($line, $body);
        }
        self::assertSame(['smtp://smtp.example.com:25?auto_tls=false'], $this->mail->dsns);
    }

    public function testIncompleteSnapshotIsExit3(): void
    {
        $this->restic->backup = [3, FakeRestic::fixture('backup-0.19-incomplete.jsonl'), FakeRestic::fixture('backup-0.19-incomplete.stderr')];
        $config = $this->writeConfig(self::notify('failure'));

        self::assertSame(ExitCode::WARNING, Artisan::call('backup', ['--config' => $config]));
        self::assertStringContainsString('warning Not backed up: /srv/www/missing does not exist, skipping', $this->logOutput());
        self::assertSame('[ArkHive] backup completed with warnings on web1.example.com', $this->mail->sent[0]->getSubject(), 'warnings count as failures for NOTIFY_ON=failure');
    }

    public function testFailureOnlyNotificationsSkipSuccesses(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify('failure'))]));
        self::assertSame([], $this->mail->sent);
    }

    public function testANotificationFailureTurnsSuccessIntoExit3(): void
    {
        $this->mail->failWith = 'Connection could not be established with host "smtp.example.com:25"';

        self::assertSame(ExitCode::WARNING, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify())]));
        self::assertStringContainsString('success Snapshot e8ecb7f4 saved', $this->logOutput());
        self::assertStringContainsString('warning The notification e-mail could not be sent through smtp.example.com:25: Connection could not be established with host "smtp.example.com:25"', $this->logOutput());
        self::assertStringContainsString('notice Check SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION, SMTP_USER and SMTP_PASSWORD', $this->logOutput());
    }

    public function testAFailedBackupIsExit1AndNotified(): void
    {
        $this->restic->catConfig = [12, '', FakeRestic::fixture('wrong-password-0.19.stderr')];

        self::assertSame(ExitCode::FAILURE, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify())]));
        self::assertStringContainsString('error Wrong password for the restic repository /srv/restic-repo', $this->logOutput());
        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] backup FAILED on web1.example.com', $mail->getSubject());
        self::assertStringStartsWith("The backup failed: Wrong password for the restic repository /srv/restic-repo (RESTIC_PASSWORD_FILE {$this->dir}/password)\nHint: Put the repository password", (string) $mail->getTextBody());
    }

    public function testA1xConfigIsExit2ListingEveryRemovedKey(): void
    {
        $config = $this->writeConfig(['SSH_HOST' => 'backup.example.com', 'WITH_CRYPT' => 'true', 'BACKUP_DIRECTORY' => '/srv/www']);

        self::assertSame(ExitCode::USAGE, Artisan::call('backup', ['--config' => $config]));
        self::assertStringContainsString("error The configuration {$config} uses keys removed in ArkHive 2.0:", $this->logOutput());
        self::assertSame([], $this->runner->commands, 'nothing ran');
    }

    public function testAWorldReadablePasswordFileIsRefusedBeforeAnythingRuns(): void
    {
        $config = $this->writeConfig();
        chmod("{$this->dir}/password", 0644);

        self::assertSame(ExitCode::USAGE, Artisan::call('backup', ['--config' => $config]));
        self::assertStringContainsString("RESTIC_PASSWORD_FILE {$this->dir}/password is readable by group or others (mode 0644) (Run: chmod 600 {$this->dir}/password)", $this->logOutput());
        self::assertSame([], $this->runner->commands);
    }

    public function testLegacyFlagsAreAcceptedWithANotice(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig(), '--with-progress' => true, '--with-disk-space-check' => true]));
        self::assertStringContainsString('notice --with-progress is a 1.x option and no longer needed: progress is shown automatically in interactive mode', $this->logOutput());
        self::assertStringContainsString('notice --with-disk-space-check is a 1.x option and no longer needed: free space for the dumps is always checked', $this->logOutput());
    }

    public function testUnknownKeysAreAWarning(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig(['FOO' => '1', 'BAR' => '2'])]));
        self::assertStringContainsString("warning Unknown keys in {$this->dir}/config, ignored: FOO, BAR", $this->logOutput());
    }

    public function testAMissingConfigIsExit2(): void
    {
        self::assertSame(ExitCode::USAGE, Artisan::call('backup', ['--config' => "{$this->dir}/nope"]));
        self::assertStringContainsString("error Configuration file {$this->dir}/nope (from --config) does not exist or is not readable", $this->logOutput());
    }

    public function testMissingResticNamesTheInstallCommand(): void
    {
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/empty", [], "ID=alpine\n"));

        self::assertSame(ExitCode::FAILURE, Artisan::call('backup', ['--config' => $this->writeConfig()]));
        self::assertStringContainsString('error restic is needed to back up but was not found in PATH or the standard directories', $this->logOutput());
        self::assertStringContainsString('notice Run: apk add restic', $this->logOutput());
    }
}
