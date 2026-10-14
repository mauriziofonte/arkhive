<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Socket;

/**
 * A connected AF_UNIX datagram socket through PHP's udg:// streams (no sockets extension): a path,
 * or "@name" for an abstract socket. Connected on first use; one that cannot be reached stays so
 * for the rest of the run, so it is not retried on every message.
 */
final class UnixDatagram
{
    /** @var resource|null */
    private $socket;
    private bool $broken = false;

    public function __construct(private readonly string $address)
    {
    }

    /**
     * @return bool whether the whole datagram was sent
     */
    public function send(string $datagram): bool
    {
        $socket = $this->socket();
        if ($socket === null) {
            return false;
        }
        if (@fwrite($socket, $datagram) === strlen($datagram)) {
            return true;
        }
        // the reader went away (journald restarted, syslog stopped): reconnect on the next datagram
        fclose($socket);
        $this->socket = null;

        return false;
    }

    /**
     * @return resource|null
     */
    private function socket()
    {
        if ($this->socket !== null || $this->broken) {
            return $this->socket;
        }
        $path = str_starts_with($this->address, '@') ? "\0" . substr($this->address, 1) : $this->address;
        $socket = @stream_socket_client('udg://' . $path, $code, $error, 1.0);
        if ($socket === false) {
            $this->broken = true;

            return null;
        }

        return $this->socket = $socket;
    }
}
