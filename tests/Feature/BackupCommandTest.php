<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Application\System\Lock;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Support\CapturingTransport;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\FixedClock;
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
        $this->app->instance(Notifier::class, new SmtpNotifier($this->mail->factory()));
    }

    public function testABackupAfterAFailedCheckEndsWithAWarningUntilAMaintainPasses(): void
    {
        // restic trusts its index: a backup succeeds on a repository that lost pack files
        $at = new \DateTimeImmutable('2026-10-11T04:00:00+00:00');
        $this->runs->states['maintain'] = (new JobState())->with(new RunRecord('maintain', $at, $at->modify('+5 minutes'), ExitCode::DATA_ERROR));
        $config = $this->writeConfig(self::notify());

        self::assertSame(ExitCode::WARNING, Artisan::call('backup', ['--config' => $config, '--json' => true]));

        self::assertStringContainsString('warning The last maintain (2026-10-11 06:05 GMT+0200) found the repository damaged and no maintain has passed since: this snapshot may use data the repository no longer has; repair it (arkhive maintain names the steps)', $this->logOutput());
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertCount(1, $json['warnings']);
        self::assertSame('[ArkHive] backup completed with warnings on web1.example.com', $this->mail->sent[0]->getSubject());
        self::assertSame(ExitCode::WARNING, $this->runs->load('backup')->last?->exitCode);

        $this->runs->states['maintain'] = $this->runs->states['maintain']->with(new RunRecord('maintain', $at->modify('+1 day'), $at->modify('+1 day 5 minutes'), ExitCode::SUCCESS));
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $config]));
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

        $run = $this->runs->load('backup')->last;
        self::assertNotNull($run);
        self::assertSame(['backup', 0, 'e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', [], null], [$run->job, $run->exitCode, $run->snapshotId, $run->warnings, $run->error]);
        self::assertSame($run, $this->runs->load('backup')->lastSuccess);
    }

    public function testAUnitRunRecordsItsInvocationAndWhetherItNotified(): void
    {
        $this->terminal->env['INVOCATION_ID'] = '6B1F0B6BD2B4405E8BF5E0C4D0C5E1AA';
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify())]));
        $run = $this->runs->load('backup')->last;
        self::assertSame(['6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', true], [$run?->invocation, $run?->notified]);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify('failure'))]));
        self::assertFalse($this->runs->load('backup')->last?->notified, 'a success is not mailed with NOTIFY_ON=failure');

        $this->terminal->env['INVOCATION_ID'] = 'not-an-id';
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));
        self::assertNull($this->runs->load('backup')->last?->invocation);
    }

    public function testUnderSystemdTheUnitStatusFollowsTheBackup(): void
    {
        $this->systemd->listening = true;
        $status = '{"message_type":"status","percent_done":0.42,"total_files":300,"files_done":120,"total_bytes":3328599654,"bytes_done":1288490188}';
        $this->restic->backup = [0, $status . "\n" . FakeRestic::fixture('backup-0.19-first.jsonl'), ''];

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));

        $statuses = $this->systemd->statuses();
        self::assertSame('arkhive backup started', $statuses[0]);
        self::assertContains('Backing up /srv/www: 42.0% 120/300 files 1.20 GiB/3.10 GiB', $statuses);
        self::assertSame(['READY' => '1', 'STATUS' => 'arkhive backup started'], $this->systemd->messages[0]);
        self::assertContains(['STATUS' => 'Backing up /srv/www: 42.0% 120/300 files 1.20 GiB/3.10 GiB', 'EXTEND_TIMEOUT_USEC' => '1800000000'], $this->systemd->messages);
        self::assertSame(['STOPPING' => '1', 'STATUS' => 'arkhive backup succeeded', 'EXIT_STATUS' => '0'], $this->systemd->messages[count($this->systemd->messages) - 1]);
        $backup = array_values(array_filter($this->runner->commands, static fn (Command $c): bool => in_array('backup', $c->argv, true)))[0];
        self::assertSame('2', $backup->env['RESTIC_PROGRESS_FPS'] ?? null, 'restic reports progress for the unit status');
        self::assertStringNotContainsString('42.0%', $this->logOutput(), 'and the log does not draw it');
    }

    public function testUnderSystemdAFailureIsTheFinalStatus(): void
    {
        $this->systemd->listening = true;
        $this->restic->catConfig = [10, '', FakeRestic::fixture('no-repository-0.19.stderr')];

        self::assertSame(ExitCode::UNAVAILABLE, Artisan::call('backup', ['--config' => $this->writeConfig()]));

        self::assertSame(
            ['STOPPING' => '1', 'STATUS' => 'arkhive backup failed with exit 69 (UNAVAILABLE): There is no restic repository at /srv/restic-repo', 'EXIT_STATUS' => '69'],
            $this->systemd->messages[count($this->systemd->messages) - 1],
        );
    }

    public function testOutsideSystemdNothingIsSentAndProgressIsNotAskedFor(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));

        self::assertSame([], $this->systemd->messages);
        $backup = array_values(array_filter($this->runner->commands, static fn (Command $c): bool => in_array('backup', $c->argv, true)))[0];
        self::assertArrayNotHasKey('RESTIC_PROGRESS_FPS', $backup->env);
    }

    public function testAFailedBackupIsRecordedWithItsCauseAndKeepsTheLastSuccess(): void
    {
        $config = $this->writeConfig();
        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $config]));
        $this->restic->catConfig = [10, '', FakeRestic::fixture('no-repository-0.19.stderr')];

        self::assertSame(ExitCode::UNAVAILABLE, Artisan::call('backup', ['--config' => $config]));

        $state = $this->runs->load('backup');
        self::assertSame(ExitCode::UNAVAILABLE, $state->last?->exitCode);
        self::assertSame('There is no restic repository at /srv/restic-repo', $state->last->error);
        self::assertNull($state->last->snapshotId);
        self::assertSame(ExitCode::SUCCESS, $state->lastSuccess?->exitCode);
    }

    public function testAnIncompleteBackupIsRecordedWithItsWarnings(): void
    {
        $this->restic->backup = [3, FakeRestic::fixture('backup-0.19-incomplete.jsonl'), FakeRestic::fixture('backup-0.19-incomplete.stderr')];

        self::assertSame(ExitCode::WARNING, Artisan::call('backup', ['--config' => $this->writeConfig()]));

        $run = $this->runs->load('backup')->last;
        self::assertSame(ExitCode::WARNING, $run?->exitCode);
        self::assertSame(['the snapshot was saved but 1 source item(s) could not be read'], $run->warnings);
        self::assertSame($run, $this->runs->load('backup')->lastSuccess);
    }

    public function testAStateDirectoryThatCannotBeWrittenDoesNotChangeTheExitCode(): void
    {
        $this->runs->failing = true;

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));
        self::assertStringContainsString('warning Could not record the outcome of this backup in /var/lib/arkhive: Cannot write /var/lib/arkhive/backup.json', $this->logOutput());
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

    public function testANotificationFailureIsTriedThreeTimes(): void
    {
        $this->mail->failWith = 'Connection could not be established with host "smtp.example.com:25"';

        self::assertSame(ExitCode::WARNING, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify())]));
        self::assertCount(2, $this->pause->waits);
        self::assertCount(3, $this->mail->dsns);
        self::assertMatchesRegularExpression('/warning Sending the notification: The notification e-mail could not be sent through smtp.example.com:25: .*; trying again in \d+s \(attempt 2 of 3\)/', $this->logOutput());
    }

    public function testAGreylistedNotificationIsSentOnTheNextTry(): void
    {
        $this->mail->throw = [new UnexpectedResponseException('Expected response code "250" but got code "451", with message "451 4.7.1 Greylisted, try again later".', 451)];

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify())]));
        self::assertCount(1, $this->mail->sent);
        self::assertCount(1, $this->pause->waits);
        self::assertEqualsWithDelta(15.0, $this->pause->waits[0], 3.0);
        self::assertStringContainsString('Greylisted, try again later', $this->logOutput());
    }

    public function testARejectedNotificationIsNotTriedAgain(): void
    {
        $this->mail->throw = [new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.1.1 No such user".', 550)];

        self::assertSame(ExitCode::WARNING, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify())]));
        self::assertSame([], $this->pause->waits);
        self::assertCount(1, $this->mail->dsns);
    }

    public function testARepositoryLockedByAnotherClientIsWaitedFor(): void
    {
        $tries = 0;
        $this->restic->on['backup'] = static function () use (&$tries): array {
            return ++$tries < 3 ? [11, '', FakeRestic::fixture('locked-0.19.stderr')] : [0, FakeRestic::fixture('backup-0.19-first.jsonl'), ''];
        };

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));
        self::assertSame(3, $tries);
        self::assertCount(2, $this->pause->waits);
        self::assertMatchesRegularExpression('/warning restic backup: The restic repository is locked by PID 25983 on host.example.com .*; trying again in \d+s \(waiting up to 300s in all, 0s so far\)/', $this->logOutput());
        self::assertStringContainsString('success Snapshot e8ecb7f4 saved', $this->logOutput());
    }

    public function testLockWaitBoundsTheWaitThenTheRunIsExit75(): void
    {
        $this->restic->on['backup'] = static fn (): array => [11, '', FakeRestic::fixture('locked-0.19.stderr')];

        self::assertSame(ExitCode::TEMPORARY, Artisan::call('backup', ['--config' => $this->writeConfig(['LOCK_WAIT' => '30'])]));
        self::assertEqualsWithDelta(30.0, $this->pause->total(), 1e-6);
        self::assertStringContainsString('error The restic repository is locked', $this->logOutput());
    }

    public function testLockWaitZeroFailsAtOnce(): void
    {
        $this->restic->on['backup'] = static fn (): array => [11, '', FakeRestic::fixture('locked-0.19.stderr')];

        self::assertSame(ExitCode::TEMPORARY, Artisan::call('backup', ['--config' => $this->writeConfig(['LOCK_WAIT' => '0'])]));
        self::assertSame([], $this->pause->waits);
        self::assertCount(1, $this->restic->callsOf('backup'));
    }

    public function testAnotherArkhiveRunIsWaitedForUntilItReleasesTheLock(): void
    {
        $other = Lock::forRepository('/srv/restic-repo', $this->terminal->env, new FixedClock(), 0);
        $other->acquire('maintain');
        $this->pause->during = static function (int $wait) use ($other): void {
            if ($wait === 2) {
                $other->release();
            }
        };

        self::assertSame(ExitCode::SUCCESS, Artisan::call('backup', ['--config' => $this->writeConfig()]));
        self::assertCount(2, $this->pause->waits);
        self::assertStringContainsString('warning Waiting for the repository: Another ArkHive run holds the lock for this repository', $this->logOutput());
        self::assertCount(1, $this->restic->backups);
    }

    /**
     * MySQL with two databases, blog missing on the server.
     *
     * @return array<string, string>
     */
    private function dumpsWithAMissingDatabase(): array
    {
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic', 'mariadb', 'mariadb-dump']));
        $this->runner
            ->on('mariadb', static fn (): array => [0, "100\n", ''])
            ->on('mariadb-dump', static function (Command $c): array|int {
                if (in_array('blog', $c->argv, true)) {
                    return [2, '', "mariadb-dump: Got error: 1049: \"Unknown database 'blog'\" when selecting the database\n"];
                }
                file_put_contents(substr($c->argv[8], strlen('--result-file=')), 'CREATE TABLE t (id INT);');

                return 0;
            });

        return ['WITH_MYSQL' => 'true', 'MYSQL_USER' => 'backup', 'MYSQL_DATABASES' => 'shop blog', 'DUMP_DIRECTORY' => "{$this->dir}/dumps"];
    }

    public function testAFailedDumpIsExit3WithTheOtherDumpsAndTheFilesSaved(): void
    {
        $config = $this->writeConfig([...self::notify(), ...$this->dumpsWithAMissingDatabase()]);

        self::assertSame(ExitCode::WARNING, Artisan::call('backup', ['--config' => $config, '--json' => true]));

        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertSame([['engine' => 'mysql', 'database' => 'shop', 'bytes' => 24]], $json['dumps']);
        self::assertSame('MySQL', $json['dump_failures'][0]['engine']);
        self::assertSame('blog', $json['dump_failures'][0]['database']);
        self::assertSame(ExitCode::NO_INPUT, $json['dump_failures'][0]['exit_code']);
        self::assertStringContainsString('warning The MySQL dump of blog failed, the backup goes on without it', $this->logOutput());
        self::assertStringContainsString('success Snapshot e8ecb7f4 saved', $this->logOutput());
        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] backup completed with warnings on web1.example.com', $mail->getSubject());
        self::assertStringContainsString('Failed dumps:  MySQL/blog', (string) $mail->getTextBody());
    }

    public function testWithDumpRequiredAFailedDumpIsTheExitCodeAndAFailureNotification(): void
    {
        $config = $this->writeConfig([...self::notify(), ...$this->dumpsWithAMissingDatabase(), 'DUMP_REQUIRED' => 'true']);

        self::assertSame(ExitCode::NO_INPUT, Artisan::call('backup', ['--config' => $config]));

        self::assertCount(1, $this->restic->backups, 'the files are backed up all the same');
        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] backup FAILED on web1.example.com', $mail->getSubject());
        self::assertStringStartsWith('The backup saved the files in snapshot e8ecb7f4, but database dumps required by DUMP_REQUIRED failed: the MySQL dump of blog failed: ', (string) $mail->getTextBody());
    }

    public function testAFailedBackupIsExit1AndNotified(): void
    {
        $this->restic->catConfig = [12, '', FakeRestic::fixture('wrong-password-0.19.stderr')];

        self::assertSame(ExitCode::NO_PERMISSION, Artisan::call('backup', ['--config' => $this->writeConfig(self::notify())]));
        self::assertStringContainsString('error Wrong password for the restic repository /srv/restic-repo', $this->logOutput());
        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] backup FAILED on web1.example.com', $mail->getSubject());
        self::assertStringStartsWith("The backup failed: Wrong password for the restic repository /srv/restic-repo (RESTIC_PASSWORD_FILE {$this->dir}/password)\nHint: Put the repository password", (string) $mail->getTextBody());
    }

    public function testA1xConfigIsExit2ListingEveryRemovedKey(): void
    {
        $config = $this->writeConfig(['SSH_HOST' => 'backup.example.com', 'WITH_CRYPT' => 'true', 'BACKUP_DIRECTORY' => '/srv/www']);

        self::assertSame(ExitCode::CONFIG, Artisan::call('backup', ['--config' => $config]));
        self::assertStringContainsString("error The configuration {$config} uses keys removed in ArkHive 2.0:", $this->logOutput());
        self::assertSame([], $this->runner->commands, 'nothing ran');
    }

    public function testAWorldReadablePasswordFileIsRefusedBeforeAnythingRuns(): void
    {
        $config = $this->writeConfig();
        chmod("{$this->dir}/password", 0644);

        self::assertSame(ExitCode::CONFIG, Artisan::call('backup', ['--config' => $config]));
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
        self::assertSame(ExitCode::NO_INPUT, Artisan::call('backup', ['--config' => "{$this->dir}/nope"]));
        self::assertStringContainsString("error Configuration file {$this->dir}/nope (from --config) does not exist or is not readable", $this->logOutput());
    }

    public function testMissingResticNamesTheInstallCommand(): void
    {
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/empty", [], "ID=alpine\n"));

        self::assertSame(ExitCode::UNAVAILABLE, Artisan::call('backup', ['--config' => $this->writeConfig()]));
        self::assertStringContainsString('error restic is needed to back up but was not found in PATH or the standard directories', $this->logOutput());
        self::assertStringContainsString('notice Run: apk add restic', $this->logOutput());
    }
}
