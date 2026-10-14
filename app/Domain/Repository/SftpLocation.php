<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * The SSH endpoint of an `sftp:` repository, as restic reads it: `sftp:[user@]host:path` or
 * `sftp://[user@]host[:port]/path` (IPv6 hosts in brackets).
 */
final readonly class SftpLocation
{
    public function __construct(
        public string $host,
        public ?string $user = null,
        public ?int $port = null,
    ) {
    }

    public static function parse(string $repository): ?self
    {
        if (preg_match('#^sftp://(?:([^@/]+)@)?(\[[0-9A-Fa-f:.]+\]|[^:/@\[\]]+)(?::(\d+))?/#', $repository, $m) === 1) {
            $port = ($m[3] ?? '') === '' ? null : (int) $m[3];

            return self::valid(trim($m[2], '[]'), $m[1] === '' ? null : $m[1], $port);
        }
        if (preg_match('#^sftp:(?!//)(?:([^@:/]+)@)?([^:/@\[\]]+):#', $repository, $m) === 1) {
            return self::valid($m[2], $m[1] === '' ? null : $m[1], null);
        }

        return null;
    }

    /**
     * The command restic runs to reach the server: `ssh <options> [-p port] [-l user] host -s sftp`,
     * the same as restic's own default plus the options.
     *
     * @param list<string> $options ssh options, such as ["-o", "BatchMode=yes"]
     */
    public function sshCommand(array $options): string
    {
        $argv = ['ssh', ...$options];
        if ($this->port !== null) {
            array_push($argv, '-p', (string) $this->port);
        }
        if ($this->user !== null) {
            array_push($argv, '-l', $this->user);
        }
        array_push($argv, $this->host, '-s', 'sftp');

        return implode(' ', $argv);
    }

    private static function valid(string $host, ?string $user, ?int $port): ?self
    {
        // restic splits sftp.command on spaces: anything that would need quoting is left to restic's default
        foreach ([$host, $user ?? ''] as $part) {
            if (preg_match('/[\s"\'\\\\]/', $part) === 1) {
                return null;
            }
        }
        if ($host === '' || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }

        return new self($host, $user, $port);
    }
}
