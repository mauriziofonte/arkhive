<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Repository;

use Mfonte\Arkhive\Domain\Repository\SftpLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SftpLocationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string, ?int}>
     */
    public static function repositories(): iterable
    {
        yield 'scp-like with user' => ['sftp:backup-user@backup.example.com:/srv/restic', 'backup.example.com', 'backup-user', null];
        yield 'scp-like relative path' => ['sftp:backup.example.com:restic', 'backup.example.com', null, null];
        yield 'URL with port' => ['sftp://backup-user@backup.example.com:2222//srv/restic', 'backup.example.com', 'backup-user', 2222];
        yield 'URL without user or port' => ['sftp://backup.example.com/restic', 'backup.example.com', null, null];
        yield 'URL with IPv6' => ['sftp://u@[2001:db8::5]:23/restic', '2001:db8::5', 'u', 23];
        yield 'IPv4' => ['sftp:u@192.0.2.10:/restic', '192.0.2.10', 'u', null];
        yield 'not sftp' => ['/srv/restic-repo', null, null, null];
        yield 'rest server' => ['rest:https://backup.example.com:8000/', null, null, null];
        yield 'no path' => ['sftp:backup.example.com', null, null, null];
        yield 'port out of range' => ['sftp://u@backup.example.com:70000/restic', null, null, null];
        yield 'port zero' => ['sftp://u@backup.example.com:0/restic', null, null, null];
        yield 'a user that needs quoting' => ['sftp:"a b"@backup.example.com:/restic', null, null, null];
        yield 'a backslash' => ['sftp://a\\b@backup.example.com/restic', null, null, null];
    }

    #[DataProvider('repositories')]
    public function testParsesTheRepositoryAsResticDoes(string $repository, ?string $host, ?string $user, ?int $port): void
    {
        $location = SftpLocation::parse($repository);

        self::assertSame($host, $location?->host);
        self::assertSame($user, $location?->user);
        self::assertSame($port, $location?->port);
    }

    public function testTheSshCommandIsResticsDefaultPlusTheOptions(): void
    {
        $options = ['-o', 'ServerAliveInterval=15', '-o', 'BatchMode=yes'];

        self::assertSame('ssh -o ServerAliveInterval=15 -o BatchMode=yes -p 2222 -l backup-user backup.example.com -s sftp', (new SftpLocation('backup.example.com', 'backup-user', 2222))->sshCommand($options));
        self::assertSame('ssh backup.example.com -s sftp', (new SftpLocation('backup.example.com'))->sshCommand([]));
    }
}
