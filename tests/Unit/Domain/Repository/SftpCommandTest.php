<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Repository;

use Mfonte\Arkhive\Domain\Repository\SftpCommand;
use Mfonte\Arkhive\Infrastructure\Restic\Restic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SftpCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commands(): iterable
    {
        $all = '-o ConnectTimeout=30 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes';
        yield 'the README form' => ['ssh -p 23 -i /root/.ssh/arkhive u123456@backup.example.com -s sftp', "ssh {$all} -p 23 -i /root/.ssh/arkhive u123456@backup.example.com -s sftp"];
        yield 'absolute path, extra spaces' => ['  /usr/bin/ssh   -p 23 backup.example.com -s sftp ', "/usr/bin/ssh {$all}   -p 23 backup.example.com -s sftp"];
        yield 'all set' => ['ssh -o ConnectTimeout=5 -o ServerAliveInterval=30 -o ServerAliveCountMax=2 -o BatchMode=no host -s sftp', 'ssh -o ConnectTimeout=5 -o ServerAliveInterval=30 -o ServerAliveCountMax=2 -o BatchMode=no host -s sftp'];
        yield 'some set, any case and spelling' => ['ssh -oserveraliveinterval=30 -o "BatchMode yes" host -s sftp', 'ssh -o ConnectTimeout=30 -o ServerAliveCountMax=4 -oserveraliveinterval=30 -o "BatchMode yes" host -s sftp'];
        yield 'a wrapper' => ['/usr/local/bin/box-ssh host', '/usr/local/bin/box-ssh host'];
        yield 'sshpass' => ['sshpass -f /root/pw ssh host -s sftp', 'sshpass -f /root/pw ssh host -s sftp'];
        yield 'a name ending in ssh' => ['autossh host -s sftp', 'autossh host -s sftp'];
    }

    #[DataProvider('commands')]
    public function testTheMissingKeepalivesAreAddedAfterSsh(string $command, string $expected): void
    {
        self::assertSame($expected, SftpCommand::withKeepalives($command));
    }

    public function testRunsSsh(): void
    {
        self::assertTrue(SftpCommand::runsSsh('ssh host'));
        self::assertTrue(SftpCommand::runsSsh(' /usr/bin/ssh host'));
        self::assertFalse(SftpCommand::runsSsh('autossh host'));
        self::assertFalse(SftpCommand::runsSsh('/opt/ssh/wrapper host'));
        self::assertFalse(SftpCommand::runsSsh(''));
    }

    public function testMissingOptions(): void
    {
        self::assertSame(['ConnectTimeout', 'ServerAliveInterval', 'ServerAliveCountMax', 'BatchMode'], SftpCommand::missingOptions('ssh host -s sftp'));
        self::assertSame(['ServerAliveCountMax'], SftpCommand::missingOptions("ssh -o ConnectTimeout=10 -o 'ServerAliveInterval=5' -o BatchMode=yes host"));
        self::assertSame(['ConnectTimeout', 'ServerAliveInterval', 'ServerAliveCountMax', 'BatchMode'], SftpCommand::missingOptions('ssh -l ServerAliveInterval=5 host'), 'only -o sets an option');
    }

    public function testTheOptionsAreTheOnesGivenToResticsOwnSsh(): void
    {
        $arguments = [];
        foreach (SftpCommand::OPTIONS as $name => $value) {
            $arguments[] = "-o {$name}={$value}";
        }

        self::assertSame(Restic::SFTP_ARGS, implode(' ', $arguments));
    }
}
