<?php

declare(strict_types=1);

namespace Tests\Integration\Logging;

use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Infrastructure\Logging\JournalSocket;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * The journal's native protocol, received on a datagram socket like journald's.
 */
final class JournalSocketTest extends TestCase
{
    use TempDir;

    public function testOneDatagramPerEventWithTheFields(): void
    {
        $path = $this->tempDir() . '/socket';
        $server = stream_socket_server('udg://' . $path, $code, $error, STREAM_SERVER_BIND);
        self::assertIsResource($server, $error);
        $journal = new JournalSocket($path);

        self::assertTrue($journal->write(LogLevel::Warning, 'Skipped /srv/www/cache: permission denied', ['ARKHIVE_COMMAND' => 'backup', 'MESSAGE_ID' => '0dca6b902c5ac3d8b27d93e0ffcc4c5e']));
        self::assertSame(
            "MESSAGE=Skipped /srv/www/cache: permission denied\nPRIORITY=4\nSYSLOG_IDENTIFIER=arkhive\nARKHIVE_COMMAND=backup\nMESSAGE_ID=0dca6b902c5ac3d8b27d93e0ffcc4c5e\n",
            stream_socket_recvfrom($server, 65536),
        );

        // a value with a line break goes in the binary form: name, newline, 64-bit LE length, value
        self::assertTrue($journal->write(LogLevel::Error, "restic failed:\nFatal: unable to open repository", []));
        $message = "restic failed:\nFatal: unable to open repository";
        self::assertSame("MESSAGE\n" . pack('P', strlen($message)) . $message . "\nPRIORITY=3\nSYSLOG_IDENTIFIER=arkhive\n", stream_socket_recvfrom($server, 65536));

        // a long message is cut to fit one datagram
        self::assertTrue($journal->write(LogLevel::Info, str_repeat('x', 60000), []));
        self::assertSame('MESSAGE=' . str_repeat('x', 48 * 1024) . "...\n", strstr((string) stream_socket_recvfrom($server, 65536), "\nPRIORITY", true) . "\n");
        fclose($server);
    }

    public function testFieldNamesTheJournalWouldRejectAreLeftOut(): void
    {
        self::assertSame("GOOD=1\nARKHIVE_X2=2\n", JournalSocket::serialize(['GOOD' => '1', '_PID' => '1', 'lower' => '1', 'ARKHIVE_X2' => '2', 'BAD-NAME' => '1', str_repeat('A', 65) => '1']));
    }

    public function testNoJournalIsNotDelivered(): void
    {
        $journal = new JournalSocket($this->tempDir() . '/missing');

        self::assertFalse($journal->write(LogLevel::Info, 'nobody listens', []));
        self::assertFalse($journal->write(LogLevel::Info, 'nobody listens', []), 'not retried, still not delivered');
    }

    public function testAJournalThatRestartsIsReachedAgain(): void
    {
        $path = $this->tempDir() . '/socket';
        $server = stream_socket_server('udg://' . $path, $code, $error, STREAM_SERVER_BIND);
        self::assertIsResource($server, $error);
        $journal = new JournalSocket($path);
        self::assertTrue($journal->write(LogLevel::Info, 'one', []));
        self::assertStringStartsWith('MESSAGE=one', (string) stream_socket_recvfrom($server, 65536));

        fclose($server);
        unlink($path);
        self::assertFalse($journal->write(LogLevel::Info, 'lost while journald restarts', []));
        $server = stream_socket_server('udg://' . $path, $code, $error, STREAM_SERVER_BIND);
        self::assertIsResource($server, $error);
        self::assertTrue($journal->write(LogLevel::Info, 'two', []));
        self::assertStringStartsWith('MESSAGE=two', (string) stream_socket_recvfrom($server, 65536));
        fclose($server);
    }
}
