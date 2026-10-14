<?php

declare(strict_types=1);

namespace Tests\Integration\Systemd;

use Mfonte\Arkhive\Infrastructure\Systemd\NotifySocket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class NotifySocketTest extends TestCase
{
    use TempDir;

    /**
     * @return iterable<string, array{array<string, string>, bool, float|null}>
     */
    public static function environments(): iterable
    {
        yield 'no service manager' => [[], false, null];
        yield 'a socket path' => [['NOTIFY_SOCKET' => '/run/systemd/notify'], true, null];
        yield 'an abstract socket' => [['NOTIFY_SOCKET' => '@/org/freedesktop/systemd1/notify/1'], true, null];
        yield 'a bare @ is not an address' => [['NOTIFY_SOCKET' => '@'], false, null];
        yield 'a relative path is ignored' => [['NOTIFY_SOCKET' => 'notify'], false, null];
        yield 'a watchdog for this process' => [['NOTIFY_SOCKET' => '/run/n', 'WATCHDOG_USEC' => '60000000', 'WATCHDOG_PID' => '4242'], true, 30.0];
        yield 'a watchdog for any process' => [['NOTIFY_SOCKET' => '/run/n', 'WATCHDOG_USEC' => '10000000'], true, 5.0];
        yield 'a watchdog for another process' => [['NOTIFY_SOCKET' => '/run/n', 'WATCHDOG_USEC' => '60000000', 'WATCHDOG_PID' => '1'], true, null];
        yield 'a malformed watchdog' => [['NOTIFY_SOCKET' => '/run/n', 'WATCHDOG_USEC' => '0'], true, null];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('environments')]
    public function testTheEnvironmentSystemdSets(array $environment, bool $supervised, ?float $watchdog): void
    {
        $socket = NotifySocket::fromEnvironment($environment, 4242);

        self::assertSame($supervised, $socket->supervised());
        self::assertSame($watchdog, $socket->watchdogInterval());
    }

    public function testMessagesReachAPathSocketOneFieldPerLine(): void
    {
        $path = $this->tempDir() . '/notify';
        $server = stream_socket_server('udg://' . $path, $code, $error, STREAM_SERVER_BIND);
        self::assertIsResource($server, $error);

        $socket = NotifySocket::fromEnvironment(['NOTIFY_SOCKET' => $path], 1);
        self::assertTrue($socket->notify(['STATUS' => "Backing up\n/srv", 'EXTEND_TIMEOUT_USEC' => '1800000000']));
        self::assertTrue($socket->notify(['STOPPING' => '1']));
        self::assertFalse($socket->notify([]));

        self::assertSame("STATUS=Backing up /srv\nEXTEND_TIMEOUT_USEC=1800000000\n", stream_socket_recvfrom($server, 4096));
        self::assertSame("STOPPING=1\n", stream_socket_recvfrom($server, 4096));
        fclose($server);
    }

    public function testMessagesReachAnAbstractSocket(): void
    {
        $name = 'arkhive-test-' . bin2hex(random_bytes(6));
        $server = @stream_socket_server("udg://\0{$name}", $code, $error, STREAM_SERVER_BIND);
        if ($server === false) {
            self::markTestSkipped("abstract sockets are not available: {$error}");
        }

        self::assertTrue(NotifySocket::fromEnvironment(['NOTIFY_SOCKET' => "@{$name}"], 1)->notify(['READY' => '1']));
        self::assertSame("READY=1\n", stream_socket_recvfrom($server, 4096));
        fclose($server);
    }

    public function testAnUnreachableSocketIsIgnored(): void
    {
        $socket = NotifySocket::fromEnvironment(['NOTIFY_SOCKET' => $this->tempDir() . '/gone'], 1);

        self::assertTrue($socket->supervised());
        self::assertFalse($socket->notify(['STATUS' => 'x']));
        self::assertFalse($socket->notify(['STATUS' => 'y']), 'and stays so');
        self::assertFalse((new NotifySocket(null))->notify(['STATUS' => 'x']));
    }
}
