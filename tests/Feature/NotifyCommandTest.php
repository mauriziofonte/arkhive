<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Port\ServiceUnits;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\Domain\Schedule\UnitState;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Support\CapturingTransport;
use Tests\Support\FakeServiceUnits;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

final class NotifyCommandTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private const INVOCATION = '6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa';

    private FakeServiceUnits $units;
    private CapturingTransport $mail;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        $this->units = new FakeServiceUnits();
        $this->units->units['arkhive-backup.service'] = new UnitState('arkhive-backup.service', 'timeout', 2, 15, self::INVOCATION, 'Wed 2026-10-14 02:31:12 UTC', 'Wed 2026-10-14 08:31:12 UTC');
        $this->units->journals[self::INVOCATION] = ['Backing up /srv/www', 'Sending SIGTERM to restic'];
        $this->mail = new CapturingTransport();
        $this->app->instance(ServiceUnits::class, $this->units);
        $this->app->instance(Notifier::class, new SmtpNotifier($this->mail->factory()));
    }

    public function testAFailedUnitIsReportedWithItsJournal(): void
    {
        $config = $this->writeConfig(self::notify('failure'));

        self::assertSame(ExitCode::SUCCESS, Artisan::call('notify', ['--failed-unit' => 'arkhive-backup.service', '--config' => $config]), $this->logOutput());

        self::assertCount(1, $this->mail->sent);
        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] backup FAILED on web1.example.com', $mail->getSubject());
        $body = (string) $mail->getTextBody();
        self::assertStringStartsWith("The systemd unit arkhive-backup.service failed: killed by signal 15 (TERM), result timeout.\n\n", $body);
        foreach (['Unit:          arkhive-backup.service', 'Outcome:       killed by signal 15 (TERM), result timeout', 'Started:       Wed 2026-10-14 02:31:12 UTC', 'Ended:         Wed 2026-10-14 08:31:12 UTC', 'Invocation:    ' . self::INVOCATION, 'Host:          web1.example.com', 'Log:           the last 2 lines of the run below'] as $line) {
            self::assertStringContainsString($line, $body);
        }
        self::assertStringEndsWith("\n\nBacking up /srv/www\nSending SIGTERM to restic\n", $body);
        self::assertStringContainsString('success Sent the notification about the failed arkhive-backup.service', $this->logOutput());
        self::assertSame([], $this->runs->states, 'notify is not a run of a job');
    }

    public function testARunThatNotifiedItselfIsNotReportedTwice(): void
    {
        $at = new DateTimeImmutable('2026-10-14T02:31:12+00:00');
        $this->runs->states['backup'] = (new JobState())->with(new RunRecord('backup', $at, $at, 69, error: 'unreachable', invocation: self::INVOCATION, notified: true));

        self::assertSame(ExitCode::SUCCESS, Artisan::call('notify', ['--failed-unit' => 'arkhive-backup.service', '--config' => $this->writeConfig(self::notify('failure'))]));

        self::assertSame([], $this->mail->sent);
        self::assertStringContainsString('info arkhive-backup.service sent its own notification about this run (killed by signal 15 (TERM), result timeout): nothing more to send', $this->logOutput());
    }

    public function testAnEarlierRunOrAnUnsentNotificationIsNoReason(): void
    {
        $at = new DateTimeImmutable('2026-10-14T02:31:12+00:00');
        $config = $this->writeConfig(self::notify('failure'));
        $this->runs->states['backup'] = (new JobState())->with(new RunRecord('backup', $at, $at, 69, invocation: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', notified: true));
        self::assertSame(ExitCode::SUCCESS, Artisan::call('notify', ['--failed-unit' => 'arkhive-backup.service', '--config' => $config]));

        $this->runs->states['backup'] = (new JobState())->with(new RunRecord('backup', $at, $at, 69, invocation: self::INVOCATION, notified: false));
        self::assertSame(ExitCode::SUCCESS, Artisan::call('notify', ['--failed-unit' => 'arkhive-backup.service', '--config' => $config]));

        self::assertCount(2, $this->mail->sent);
    }

    public function testAnyOtherUnitIsNamedAsIs(): void
    {
        $this->units->units['web-deploy.service'] = new UnitState('web-deploy.service', 'exit-code', 1, 1, null);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('notify', ['--failed-unit' => 'web-deploy.service', '--config' => $this->writeConfig(self::notify())]));

        self::assertSame('[ArkHive] web-deploy.service FAILED on web1.example.com', $this->mail->sent[0]->getSubject());
        self::assertStringContainsString('Log:           not available (journalctl -u web-deploy.service)', (string) $this->mail->sent[0]->getTextBody());
    }

    public function testWithoutNotificationsNothingIsSent(): void
    {
        self::assertSame(ExitCode::WARNING, Artisan::call('notify', ['--failed-unit' => 'arkhive-backup.service', '--config' => $this->writeConfig()]));

        self::assertSame([], $this->mail->sent);
        self::assertStringContainsString('warning arkhive-backup.service failed (killed by signal 15 (TERM), result timeout), but no notifications are configured: nothing sent', $this->logOutput());
    }

    public function testAMailThatCannotBeSentKeepsItsExitCode(): void
    {
        $this->mail->throw = array_fill(0, 3, new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.7.1 Relaying denied".', 550));

        self::assertSame(ExitCode::FAILURE, Artisan::call('notify', ['--failed-unit' => 'arkhive-backup.service', '--config' => $this->writeConfig(self::notify())]));
        self::assertStringContainsString('550 5.7.1 Relaying denied', $this->logOutput());
    }

    public function testUsageErrors(): void
    {
        self::assertSame(ExitCode::USAGE, Artisan::call('notify', ['--config' => $this->writeConfig()]));
        self::assertStringContainsString('error notify needs --failed-unit', $this->logOutput());

        self::assertSame(ExitCode::USAGE, Artisan::call('notify', ['--failed-unit' => 'arkhive-backup.timer', '--config' => $this->writeConfig()]));
        self::assertSame(ExitCode::USAGE, Artisan::call('notify', ['--failed-unit' => '../x.service', '--config' => $this->writeConfig()]));
        self::assertStringContainsString("error --failed-unit must name a service, such as arkhive-backup.service, not '../x.service'", $this->logOutput());

        self::assertSame(ExitCode::NO_INPUT, Artisan::call('notify', ['--failed-unit' => 'nope.service', '--config' => $this->writeConfig()]));
    }
}
