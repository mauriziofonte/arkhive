<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\BackupStatus;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Restic\Restic;
use Mfonte\Arkhive\Infrastructure\Restic\ResticErrors;
use Mfonte\Arkhive\Infrastructure\Restic\ResticFactory;
use Mfonte\Arkhive\Infrastructure\Restic\ResticJson;
use Mfonte\Arkhive\Infrastructure\Restic\ResticOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\TempDir;

/**
 * restic output parsing and error classification, against output captured from real restic
 * 0.19.1 and 0.16.4 (tests/Fixtures/restic).
 */
final class ResticTest extends TestCase
{
    use TempDir;

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/restic/' . $name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function messages(string $name): array
    {
        return array_values(array_filter(array_map(ResticJson::decode(...), explode("\n", self::fixture($name)))));
    }

    private static function settings(string $repository = 'sftp:backup-user@backup.example.com:/restic', ?string $sftpCommand = null): ResticSettings
    {
        return new ResticSettings($repository, '/etc/arkhive/restic-password', $sftpCommand, 'restic', 'host.example.com', 'auto');
    }

    public function testSummaryOfAFirstBackup(): void
    {
        $messages = self::messages('backup-0.19-first.jsonl');
        $summary = ResticOutput::backupSummary($messages[count($messages) - 1]);

        self::assertSame('e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', $summary->snapshotId);
        self::assertSame('e8ecb7f4', $summary->shortId());
        self::assertSame([51, 0, 0], [$summary->filesNew, $summary->filesChanged, $summary->filesUnmodified]);
        self::assertSame([9, 0, 0], [$summary->dirsNew, $summary->dirsChanged, $summary->dirsUnmodified]);
        self::assertSame(1295973, $summary->dataAdded);
        self::assertSame(1284878, $summary->dataAddedPacked);
        self::assertSame(51, $summary->totalFilesProcessed);
        self::assertSame(1275006, $summary->totalBytesProcessed);
        self::assertEqualsWithDelta(0.664891611, $summary->totalDuration, 1e-9);
        self::assertSame('snapshot e8ecb7f4 saved: 1.24 MiB added (1.22 MiB processed), 51 new, 0 changed, 0 unmodified files in 0.7s', $summary->describe());
        self::assertSame('e8ecb7f41958b511ccbdba5c2db6c0ab81cdf6b2cb2afd345240800c7bf7c036', $summary->toArray()['snapshot_id']);
        self::assertCount(12, $summary->toArray());
    }

    public function testSummaryOfAnIncompleteBackupAndOfRestic016(): void
    {
        $incomplete = ResticOutput::backupSummary(self::messages('backup-0.19-incomplete.jsonl')[0]);
        self::assertSame([0, 0, 51], [$incomplete->filesNew, $incomplete->filesChanged, $incomplete->filesUnmodified]);
        self::assertSame(2176, $incomplete->dataAdded);

        $old = ResticOutput::backupSummary(self::messages('backup-0.16.jsonl')[0]);
        self::assertNull($old->dataAddedPacked, '0.16 has no data_added_packed');
        self::assertSame('0d8c34f4ed8213e4693a7b72e02b0e3bf1a87f2c89b05c0d9cf6fac078389f6f', $old->snapshotId);
        self::assertSame(2176, $old->dataAdded);
    }

    public function testStatusLines(): void
    {
        $statuses = array_values(array_filter(self::messages('backup-0.19-progress.jsonl'), static fn (array $m): bool => $m['message_type'] === 'status'));
        self::assertNotEmpty($statuses);
        $status = ResticOutput::backupStatus($statuses[0]);

        self::assertSame(' 40.0%  9/30 files  34.33 MiB/85.83 MiB', $status->describe());
        self::assertSame(' 50.0%  1/2 files  1.00 KiB/2.00 KiB  ETA 1:05', (new BackupStatus(0.5, 1, 2, 1024, 2048, 65))->describe());
    }

    public function testDecodeIgnoresAnythingThatIsNotAJsonObject(): void
    {
        self::assertNull(ResticJson::decode(''));
        self::assertNull(ResticJson::decode('repository 1a94dab1 opened (version 2, compression level auto)'));
        self::assertNull(ResticJson::decode('{broken'));
        self::assertNull(ResticJson::decode('{"a":1'));
        self::assertSame(['a' => 1], ResticJson::decode("  {\"a\":1}\r"));
        self::assertSame(0, ResticJson::int(['a' => 'x'], 'a'));
        self::assertSame(2, ResticJson::int(['a' => 2.7], 'a'));
        self::assertSame(0.0, ResticJson::float(['a' => null], 'a'));
        self::assertSame('', ResticJson::string(['a' => 3], 'a'));
    }

    public function testVersionParsing(): void
    {
        self::assertSame('0.19.1', Restic::parseVersion(self::fixture('version-0.19.txt')));
        self::assertSame('0.16.4', Restic::parseVersion(self::fixture('version-0.16.txt')));
        self::assertNull(Restic::parseVersion('rustic 0.9.0'));
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function failures(): iterable
    {
        $unlock = "If no other restic process is using the repository the lock is stale; remove it with: RESTIC_PASSWORD_FILE='/etc/arkhive/restic-password' restic -r 'sftp:backup-user@backup.example.com:/restic' unlock (ArkHive never does this automatically)";
        yield 'wrong password 0.19' => ['wrong-password-0.19.stderr', 12, 'Wrong password for the restic repository sftp:backup-user@backup.example.com:/restic (RESTIC_PASSWORD_FILE /etc/arkhive/restic-password)', 'Put the repository password in that file; it is the password given to restic init, kept off the host as well', ExitCode::NO_PERMISSION];
        yield 'wrong password 0.16' => ['wrong-password-0.16.stderr', 1, 'Wrong password for the restic repository sftp:backup-user@backup.example.com:/restic (RESTIC_PASSWORD_FILE /etc/arkhive/restic-password)', 'Put the repository password in that file; it is the password given to restic init, kept off the host as well', ExitCode::NO_PERMISSION];
        yield 'no repository 0.19' => ['no-repository-0.19.stderr', 10, 'There is no restic repository at sftp:backup-user@backup.example.com:/restic', 'Check RESTIC_REPOSITORY, or create the repository with: arkhive init', ExitCode::UNAVAILABLE];
        yield 'no repository 0.16' => ['no-repository-0.16.stderr', 1, 'There is no restic repository at sftp:backup-user@backup.example.com:/restic', 'Check RESTIC_REPOSITORY, or create the repository with: arkhive init', ExitCode::UNAVAILABLE];
        yield 'locked 0.19' => ['locked-0.19.stderr', 11, 'The restic repository is locked by PID 25983 on host.example.com by root (UID 0, GID 0), created at 2026-10-12 13:58:02 (1.554904747s ago)', $unlock, ExitCode::TEMPORARY];
        yield 'locked 0.16' => ['locked-0.19.stderr', 1, 'The restic repository is locked by PID 25983 on host.example.com by root (UID 0, GID 0), created at 2026-10-12 13:58:02 (1.554904747s ago)', $unlock, ExitCode::TEMPORARY];
        yield 'sftp unreachable' => ['sftp-unreachable-0.19.stderr', 1, 'Opening the restic repository failed (restic exit 1): subprocess ssh: ssh: connect to host backup.example.com port 2299: Connection refused | Fatal: unable to open repository at sftp:e2e@backup.example.com:/nothere: unable to start the sftp session, error: error receiving version packet from server: server unexpectedly closed connection: unexpected EOF', 'Check that the SFTP server is up and accepts the SSH key of this user; for a non-standard port or key set RESTIC_SFTP_COMMAND', ExitCode::UNAVAILABLE];
        yield 'sftp unreachable, ssh stderr not relayed' => ['sftp-unreachable-no-ssh-line-0.19.stderr', 1, 'Opening the restic repository failed (restic exit 1): Fatal: unable to open repository at sftp:e2e@backup.example.com:/nothere: unable to start the sftp session, error: error receiving version packet from server: server unexpectedly closed connection: unexpected EOF', 'Check that the SFTP server is up and accepts the SSH key of this user; for a non-standard port or key set RESTIC_SFTP_COMMAND', ExitCode::UNAVAILABLE];
    }

    #[DataProvider('failures')]
    public function testFailuresAreExplainedWithTheFix(string $fixture, int $exit, string $message, string $hint, int $arkhiveExit): void
    {
        $result = new ProcessResult(new Command(['/usr/bin/restic', 'cat', 'config']), $exit, '', self::fixture($fixture));
        $e = ResticErrors::exception($result, 'Opening the restic repository', self::settings());

        self::assertSame($message, $e->getMessage());
        self::assertSame($hint, $e->hint());
        self::assertSame($arkhiveExit, $e->exitCode());
    }

    public function testAFullRepositoryFilesystemIsAnIoErrorAndALostSshSessionUnavailable(): void
    {
        $command = new Command(['/usr/bin/restic', 'backup']);
        $full = ResticErrors::exception(new ProcessResult($command, 1, '', "Fatal: unable to save snapshot: write /srv/restic-repo/data/ab/abcd: no space left on device\n"), 'restic backup', self::settings('/srv/restic-repo'));
        self::assertSame(ExitCode::IO_ERROR, $full->exitCode());
        self::assertSame('restic backup failed (restic exit 1): Fatal: unable to save snapshot: write /srv/restic-repo/data/ab/abcd: no space left on device', $full->getMessage());
        self::assertSame('The filesystem holding the repository (or the restic cache) is full: free space, or prune old snapshots with arkhive maintain', $full->hint());

        $lost = ResticErrors::exception(new ProcessResult($command, 1, '', "Fatal: unable to save snapshot: ssh command exited: signal: killed\n"), 'restic backup', self::settings());
        self::assertSame(ExitCode::UNAVAILABLE, $lost->exitCode());
        self::assertSame('Check that the SFTP server is up and accepts the SSH key of this user; for a non-standard port or key set RESTIC_SFTP_COMMAND', $lost->hint());
    }

    public function testUnknownFailuresKeepStderrAndTimeoutsPointAtTheServer(): void
    {
        $command = new Command(['/usr/bin/restic', 'check']);
        $other = ResticErrors::exception(new ProcessResult($command, 1, '', "Fatal: pack 1234 damaged\n"), 'Repository check', self::settings('/srv/restic-repo'));
        self::assertSame('Repository check failed (restic exit 1): Fatal: pack 1234 damaged', $other->getMessage());
        self::assertNull($other->hint());
        self::assertSame(ExitCode::FAILURE, $other->exitCode());

        $timeout = ResticErrors::exception(new ProcessResult($command->withTimeout(300.0), 143, '', '', 300.0, true), 'Opening the restic repository', self::settings('sftp:x@backup.example.com:/r', 'ssh -p 23 x@backup.example.com -s sftp'));
        self::assertSame('Opening the restic repository timed out after 300s (restic)', $timeout->getMessage());
        self::assertSame('Check that the repository server is up and that this command works by hand: ssh -p 23 x@backup.example.com -s sftp', $timeout->hint());
        self::assertSame(ExitCode::TEMPORARY, $timeout->exitCode());

        $local = ResticErrors::exception(new ProcessResult($command, 1, '', 'ssh: lost'), 'x', self::settings('/srv/restic-repo'));
        self::assertSame('Check that the repository location is reachable', $local->hint());
    }

    public function testCommandsCarryTheRepositoryAndPasswordFileInTheEnvironmentOnly(): void
    {
        $restic = new Restic(self::settings(), '/usr/bin/restic', new FakeProcessRunner());
        $command = $restic->command(['snapshots', '--json'], ['RESTIC_PROGRESS_FPS' => '2']);

        self::assertSame(['/usr/bin/restic', '--option', 'sftp.args=-o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes', 'snapshots', '--json'], $command->argv);
        self::assertSame([
            'RESTIC_REPOSITORY' => 'sftp:backup-user@backup.example.com:/restic',
            'RESTIC_PASSWORD_FILE' => '/etc/arkhive/restic-password',
            'RESTIC_PASSWORD' => null,
            'RESTIC_PASSWORD_COMMAND' => null,
            'RESTIC_REPOSITORY_FILE' => null,
            'RESTIC_PROGRESS_FPS' => '2',
        ], $command->env);

        $custom = new Restic(self::settings(sftpCommand: 'ssh -i /root/.ssh/arkhive_backup -p 23 backup-user@backup.example.com -s sftp'), '/usr/bin/restic', new FakeProcessRunner());
        self::assertSame(['/usr/bin/restic', '--option', 'sftp.command=ssh -i /root/.ssh/arkhive_backup -p 23 backup-user@backup.example.com -s sftp', 'cat', 'config'], $custom->command(['cat', 'config'])->argv);

        $local = new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', new FakeProcessRunner());
        self::assertSame(['/usr/bin/restic', 'cat', 'config'], $local->command(['cat', 'config'])->argv);
    }

    public function testVersionChecks(): void
    {
        $runner = (new FakeProcessRunner())->on('restic', static fn (): array => [0, self::fixture('version-0.19.txt'), '']);
        self::assertSame('0.19.1', (new Restic(self::settings(), '/usr/bin/restic', $runner))->requireSupportedVersion());

        $old = (new FakeProcessRunner())->on('restic', static fn (): array => [0, "restic 0.13.1 compiled with go1.18 on linux/amd64\n", '']);
        try {
            (new Restic(self::settings(), '/usr/bin/restic', $old))->requireSupportedVersion();
            self::fail('expected a refusal');
        } catch (ArkhiveException $e) {
            self::assertSame('restic 0.13.1 is too old: ArkHive needs restic 0.14.0 or newer (repository format 2, compression)', $e->getMessage());
            self::assertSame('Install a newer restic from your distribution, or the static binary from https://github.com/restic/restic/releases (then set RESTIC_BINARY)', $e->hint());
        }

        $broken = (new FakeProcessRunner())->on('restic', static fn (): array => [126, '', 'exec format error']);
        $this->expectExceptionObject(new ArkhiveException('Running /usr/bin/restic version failed (restic exit 126): exec format error'));
        (new Restic(self::settings(), '/usr/bin/restic', $broken))->version();
    }

    public function testReachabilityUsesCatConfigWithATimeout(): void
    {
        $runner = (new FakeProcessRunner())->on('restic', static fn (Command $c): array => [0, "{\"version\": 2}\n", '']);
        (new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', $runner))->checkReachable();

        self::assertSame(['/usr/bin/restic', 'cat', 'config'], $runner->commands[0]->argv);
        self::assertSame(300.0, $runner->commands[0]->timeout);

        $timedOut = (new FakeProcessRunner())->on('restic', static fn (Command $c): ProcessResult => new ProcessResult($c, 143, '', '', 300.0, true));
        $this->expectExceptionObject(new ArkhiveException('Opening the restic repository timed out after 300s (restic)'));
        (new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', $timedOut))->checkReachable();
    }

    public function testInitReturnsTheRepositoryId(): void
    {
        $runner = (new FakeProcessRunner())->on('restic', static fn (): array => [0, "{\"message_type\":\"initialized\",\"id\":\"1a94dab1\",\"repository\":\"/srv/restic-repo\"}\n", '']);
        self::assertSame('1a94dab1', (new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', $runner))->init());

        $noId = (new FakeProcessRunner())->on('restic', static fn (): array => [0, "created restic repository\n", '']);
        self::assertSame('', (new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', $noId))->init());
    }

    public function testInitOfAnExistingRepositoryIsNullOn019And016(): void
    {
        $json = (new FakeProcessRunner())->on('restic', static fn (): array => [1, '', '{"message_type":"exit_error","code":1,"message":"Fatal: Fatal: create repository at /srv/restic-repo failed: config file already exists"}' . "\n"]);
        self::assertNull((new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', $json))->init());

        $text = (new FakeProcessRunner())->on('restic', static fn (): array => [1, '', "Fatal: create repository at /srv/restic-repo failed: config file already exists\n"]);
        self::assertNull((new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', $text))->init());
    }

    public function testInitFailuresAreErrors(): void
    {
        $runner = (new FakeProcessRunner())->on('restic', static fn (): array => [1, '', "Fatal: create repository at /srv/restic-repo failed: mkdir /srv/restic-repo: permission denied\n"]);

        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('Creating the restic repository failed (restic exit 1): Fatal: create repository at /srv/restic-repo failed: mkdir /srv/restic-repo: permission denied');
        (new Restic(self::settings('/srv/restic-repo'), '/usr/bin/restic', $runner))->init();
    }

    public function testFactoryFindsResticOrTheConfiguredBinary(): void
    {
        $dir = $this->tempDir();
        $dependencies = FakeBinaries::dependencies("{$dir}/bin", ['restic', 'restic-0.19']);
        $factory = new ResticFactory(new FakeProcessRunner(), $dependencies);

        self::assertSame("{$dir}/bin/restic", $factory->create(self::settings(), 'to back up')->binary);
        $custom = new ResticSettings('/srv/r', '/etc/p', null, 'restic-0.19');
        self::assertSame("{$dir}/bin/restic-0.19", $factory->create($custom, 'to back up')->binary);

        $this->expectExceptionObject(new ArkhiveException('restic is needed to back up but /opt/restic is not an executable file (from RESTIC_BINARY)'));
        $factory->create(new ResticSettings('/srv/r', '/etc/p', null, '/opt/restic'), 'to back up');
    }
}
