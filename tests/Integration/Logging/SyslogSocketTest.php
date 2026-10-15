<?php

declare(strict_types=1);

namespace Tests\Integration\Logging;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Infrastructure\Logging\SyslogSocket;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;

/**
 * syslog(3)'s local format, received on a datagram socket like /dev/log.
 */
final class SyslogSocketTest extends TestCase
{
    use TempDir;

    public function testTheLineSyslogDaemonsRead(): void
    {
        $path = $this->tempDir() . '/log';
        $server = stream_socket_server('udg://' . $path, $code, $error, STREAM_SERVER_BIND);
        self::assertIsResource($server, $error);
        $clock = new FixedClock(new DateTimeImmutable('2026-10-04 02:31:12', new DateTimeZone('UTC')));
        $syslog = new SyslogSocket(SyslogFacility::Daemon, $clock, 4242, $path);

        self::assertTrue($syslog->write(LogLevel::Error, "restic failed:\n  Fatal: wrong password", ['ARKHIVE_COMMAND' => 'backup', 'ARKHIVE_LEVEL' => 'error', 'ARKHIVE_PHASE' => 'Backing up /srv', 'ARKHIVE_REPOSITORY' => 'sftp:backup@backup.example.com:/restic', 'ARKHIVE_REASON' => 'said "no"', 'MESSAGE_ID' => '0dca6b902c5ac3d8b27d93e0ffcc4c5e']));
        self::assertSame(
            '<27>Oct  4 02:31:12 arkhive[4242]: restic failed: | Fatal: wrong password command=backup repository=sftp:backup@backup.example.com:/restic reason="said \"no\"" message_id=0dca6b902c5ac3d8b27d93e0ffcc4c5e',
            stream_socket_recvfrom($server, 65536),
        );

        $clock->now = new DateTimeImmutable('2026-10-14 19:36:00', new DateTimeZone('UTC'));
        self::assertTrue((new SyslogSocket(SyslogFacility::Local3, $clock, 7, $path))->write(LogLevel::Info, 'Backed up', []));
        self::assertSame('<158>Oct 14 19:36:00 arkhive[7]: Backed up', stream_socket_recvfrom($server, 65536));

        self::assertTrue($syslog->write(LogLevel::Info, str_repeat('y', 9000), []));
        self::assertSame(8096, strlen((string) stream_socket_recvfrom($server, 65536)), 'cut to what syslog daemons accept');
        fclose($server);
    }

    public function testNoSyslogDaemonIsNotDelivered(): void
    {
        self::assertFalse((new SyslogSocket(SyslogFacility::Daemon, new FixedClock(), 1, $this->tempDir() . '/log'))->write(LogLevel::Info, 'nobody listens', []));
    }

    public function testPairs(): void
    {
        self::assertSame(' command=backup empty="" spaces="a b" multi="a | b"', SyslogSocket::pairs(['ARKHIVE_COMMAND' => 'backup', 'ARKHIVE_EMPTY' => '', 'ARKHIVE_SPACES' => 'a b', 'ARKHIVE_MULTI' => "a\nb"]));
    }
}
