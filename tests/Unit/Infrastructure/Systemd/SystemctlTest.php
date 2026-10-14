<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Systemd;

use Mfonte\Arkhive\Application\Process\Timeouts;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Systemd\Systemctl;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\TempDir;

final class SystemctlTest extends TestCase
{
    use TempDir;

    private const SHOW = "Id=arkhive-backup.service\nLoadState=loaded\nResult=timeout\nExecMainCode=2\nExecMainStatus=15\nInvocationID=6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa\nExecMainStartTimestamp=Wed 2026-10-14 02:31:12 UTC\nExecMainExitTimestamp=Wed 2026-10-14 08:31:12 UTC\n";

    public function testTheStateOfAUnit(): void
    {
        $dir = $this->tempDir();
        $runner = (new FakeProcessRunner())->on('systemctl', static fn (): array => [0, self::SHOW, '']);
        $state = (new Systemctl($runner, FakeBinaries::dependencies($dir, ['systemctl'])))->state('arkhive-backup.service');

        self::assertSame('killed by signal 15 (TERM), result timeout', $state->describe());
        self::assertSame('6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', $state->invocationId);
        self::assertSame([
            "{$dir}/systemctl", 'show', '--no-pager',
            '--property=Id,LoadState,Result,ExecMainCode,ExecMainStatus,InvocationID,ExecMainStartTimestamp,ExecMainExitTimestamp',
            '--', 'arkhive-backup.service',
        ], $runner->commands[0]->argv);
        self::assertSame(Timeouts::VERSION, $runner->commands[0]->timeout);
    }

    public function testAnUnknownUnitAndAFailingSystemctl(): void
    {
        $dir = $this->tempDir();
        $unknown = (new FakeProcessRunner())->on('systemctl', static fn (): array => [0, "Id=nope.service\nLoadState=not-found\n", '']);
        try {
            (new Systemctl($unknown, FakeBinaries::dependencies($dir, ['systemctl'])))->state('nope.service');
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame(ExitCode::NO_INPUT, $e->exitCode());
            self::assertSame('systemd has no unit nope.service', $e->getMessage());
        }

        $offline = (new FakeProcessRunner())->on('systemctl', static fn (): array => [1, '', "System has not been booted with systemd as init system (PID 1). Can't operate.\n"]);
        try {
            (new Systemctl($offline, FakeBinaries::dependencies($dir, ['systemctl'])))->state('arkhive-backup.service');
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame(ExitCode::UNAVAILABLE, $e->exitCode());
            self::assertStringStartsWith('Reading the state of arkhive-backup.service failed (systemctl exit 1): System has not been booted', $e->getMessage());
        }

        try {
            (new Systemctl(new FakeProcessRunner(), FakeBinaries::dependencies($this->tempDir(), [])))->state('arkhive-backup.service');
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame(ExitCode::UNAVAILABLE, $e->exitCode());
            self::assertStringContainsString('systemctl', $e->getMessage());
        }
    }

    public function testTheJournalOfOneInvocation(): void
    {
        $dir = $this->tempDir();
        $runner = (new FakeProcessRunner())->on('journalctl', static fn (): array => [0, "Backing up /srv\n\nThe restic repository is locked\n", '']);
        $systemctl = new Systemctl($runner, FakeBinaries::dependencies($dir, ['journalctl']));

        self::assertSame(['Backing up /srv', 'The restic repository is locked'], $systemctl->journal('6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', 30));
        self::assertSame(["{$dir}/journalctl", '--no-pager', '--quiet', '--output=cat', '--lines=30', '_SYSTEMD_INVOCATION_ID=6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa'], $runner->commands[0]->argv);
        self::assertSame(Timeouts::QUERY, $runner->commands[0]->timeout);
        self::assertSame([], $systemctl->journal('not-an-id', 30));
        self::assertCount(1, $runner->commands, 'a malformed id is never passed on');

        $denied = new Systemctl((new FakeProcessRunner())->on('journalctl', static fn (): array => [1, '', "No journal files were opened due to insufficient permissions.\n"]), FakeBinaries::dependencies($dir, ['journalctl']));
        self::assertSame([], $denied->journal('6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', 30));
        self::assertSame([], (new Systemctl(new FakeProcessRunner(), FakeBinaries::dependencies($this->tempDir(), [])))->journal('6b1f0b6bd2b4405e8bf5e0c4d0c5e1aa', 30));
    }

    public function testPropertiesParsing(): void
    {
        self::assertSame(['A' => 'b=c', 'Empty' => ''], Systemctl::properties("A=b=c\n=nokey\nnoequals\nEmpty=\n"));
    }
}
