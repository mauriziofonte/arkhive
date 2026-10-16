<?php

declare(strict_types=1);

namespace Tests\Compat;

use Mfonte\Arkhive\ExitCode;
use RuntimeException;

/**
 * An sftp: repository on a throw-away local sshd, reached through restic's own ssh command or a
 * RESTIC_SFTP_COMMAND: the keepalive options reach ssh on every restic release (as sftp.args from
 * 0.16.1, as a whole sftp.command before, added to a custom command). An `ssh` wrapper first in PATH records what restic runs.
 */
final class SftpTest extends CompatTestCase
{
    /** @var resource|null */
    private $sshd = null;

    protected function tearDown(): void
    {
        if (is_resource($this->sshd)) {
            proc_terminate($this->sshd);
            proc_close($this->sshd);
        }
    }

    public function testKeepalivesReachSshOnEveryRelease(): void
    {
        $this->assertKeepalivesReachSsh(null);
    }

    /**
     * RESTIC_SFTP_COMMAND in the README's form (port and key, no -o): ArkHive adds the options.
     */
    public function testKeepalivesReachACustomSftpCommand(): void
    {
        $this->assertKeepalivesReachSsh(static fn (string $user, int $port): string => "ssh -p {$port} {$user}@127.0.0.1 -s sftp");
    }

    /**
     * @param (callable(string, int): string)|null $sftpCommand
     */
    private function assertKeepalivesReachSsh(?callable $sftpCommand): void
    {
        $restic = self::restic();
        $sshd = self::which('sshd', ['/usr/sbin/sshd', '/usr/local/sbin/sshd']);
        $ssh = self::which('ssh', ['/usr/bin/ssh', '/usr/local/bin/ssh']);
        $keygen = self::which('ssh-keygen', ['/usr/bin/ssh-keygen', '/usr/local/bin/ssh-keygen']);
        if ($sshd === null || $ssh === null || $keygen === null) {
            self::markTestSkipped('OpenSSH (sshd, ssh, ssh-keygen) is not installed');
        }
        $user = (posix_getpwuid(posix_geteuid()) ?: ['name' => ''])['name'];
        $dir = $this->tempDir();
        chmod($dir, 0755);
        $port = self::freePort();
        $this->startSshd($dir, $sshd, $keygen, $port);

        mkdir("{$dir}/bin");
        file_put_contents("{$dir}/ssh_config", "Host *\n  IdentityFile {$dir}/client_key\n  IdentitiesOnly yes\n  StrictHostKeyChecking no\n  UserKnownHostsFile /dev/null\n  LogLevel ERROR\n");
        file_put_contents("{$dir}/bin/ssh", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '{$dir}/ssh.log'\nexec '{$ssh}' -F '{$dir}/ssh_config' \"\$@\"\n");
        chmod("{$dir}/bin/ssh", 0755);
        $env = ['PATH' => "{$dir}/bin:" . (string) getenv('PATH')];

        mkdir("{$dir}/www");
        file_put_contents("{$dir}/www/index.html", "<h1>sftp</h1>\n");
        $config = self::writeConfig("{$dir}/arkhive.conf", [
            'BACKUP_PATHS' => "{$dir}/www",
            'RESTIC_REPOSITORY' => "sftp://{$user}@127.0.0.1:{$port}/{$dir}/repo",
            'RESTIC_PASSWORD_FILE' => "{$dir}/password",
            'RESTIC_BINARY' => $restic,
            ...($sftpCommand === null ? [] : ['RESTIC_SFTP_COMMAND' => $sftpCommand($user, $port)]),
        ]);

        $init = self::arkhive(['init', '--config', $config], $env);
        self::assertSame(ExitCode::SUCCESS, $init->exitCode, $init->output() . self::log($dir));
        $backup = self::arkhive(['backup', '--config', $config], $env);
        self::assertSame(ExitCode::SUCCESS, $backup->exitCode, $backup->output() . self::log($dir));

        $calls = array_values(array_filter(explode("\n", self::log($dir))));
        self::assertNotSame([], $calls, 'restic ran ssh');
        foreach ($calls as $call) {
            self::assertStringContainsString('-o ConnectTimeout=30 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes', $call);
            self::assertStringContainsString("-p {$port}", $call);
            self::assertStringContainsString('127.0.0.1', $call);
            self::assertStringContainsString('-s sftp', $call);
        }
    }

    private function startSshd(string $dir, string $sshd, string $keygen, int $port): void
    {
        foreach (['host_key', 'client_key'] as $key) {
            $result = self::exec([$keygen, '-q', '-t', 'ed25519', '-N', '', '-f', "{$dir}/{$key}"]);
            self::assertSame(0, $result->exitCode, $result->output());
        }
        copy("{$dir}/client_key.pub", "{$dir}/authorized_keys");
        chmod("{$dir}/authorized_keys", 0600);
        file_put_contents("{$dir}/sshd_config", implode("\n", [
            "Port {$port}",
            'ListenAddress 127.0.0.1',
            "HostKey {$dir}/host_key",
            "AuthorizedKeysFile {$dir}/authorized_keys",
            "PidFile {$dir}/sshd.pid",
            'StrictModes no',
            'PasswordAuthentication no',
            'KbdInteractiveAuthentication no',
            'PermitRootLogin prohibit-password',
            'Subsystem sftp internal-sftp',
            'LogLevel ERROR',
            '',
        ]));
        if (posix_geteuid() === 0 && !is_dir('/run/sshd')) {
            // sshd started as root wants its privilege separation directory
            @mkdir('/run/sshd', 0755, true);
        }
        $process = proc_open([$sshd, '-D', '-e', '-f', "{$dir}/sshd_config"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/sshd.out", 'a'], 2 => ['file', "{$dir}/sshd.out", 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start sshd');
        }
        $this->sshd = $process;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50000);
        }
        self::markTestSkipped('sshd did not start here: ' . trim((string) @file_get_contents("{$dir}/sshd.out")));
    }

    private static function log(string $dir): string
    {
        return (string) @file_get_contents("{$dir}/ssh.log");
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            throw new RuntimeException("no free port: {$error}");
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @param list<string> $candidates
     */
    private static function which(string $name, array $candidates): ?string
    {
        foreach (explode(':', (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable("{$directory}/{$name}")) {
                return "{$directory}/{$name}";
            }
        }
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
