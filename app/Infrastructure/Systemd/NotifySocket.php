<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Systemd;

use Mfonte\Arkhive\Application\Port\ServiceManager;

/**
 * systemd's notify protocol (sd_notify(3)) in PHP: one datagram per message, "KEY=value" lines, to
 * the AF_UNIX socket in $NOTIFY_SOCKET ("@name" is an abstract socket). No libsystemd, no sockets
 * extension: PHP's udg:// streams.
 */
final class NotifySocket implements ServiceManager
{
    /** @var resource|null */
    private $socket;
    private bool $broken = false;

    /**
     * @param string|null $address        $NOTIFY_SOCKET
     * @param int|null    $watchdogMicros $WATCHDOG_USEC, when $WATCHDOG_PID is unset or this process
     */
    public function __construct(private readonly ?string $address, private readonly ?int $watchdogMicros = null)
    {
    }

    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment, int $pid): self
    {
        $address = $environment['NOTIFY_SOCKET'] ?? '';
        $valid = str_starts_with($address, '/') || (str_starts_with($address, '@') && strlen($address) > 1);
        $micros = $environment['WATCHDOG_USEC'] ?? '';
        $watchdogPid = $environment['WATCHDOG_PID'] ?? '';
        $watchdog = preg_match('/^[1-9]\d{0,15}$/', $micros) === 1 && ($watchdogPid === '' || $watchdogPid === (string) $pid) ? (int) $micros : null;

        return new self($valid ? $address : null, $watchdog);
    }

    public function supervised(): bool
    {
        return $this->address !== null;
    }

    public function notify(array $state): bool
    {
        if ($this->address === null || $this->broken || $state === []) {
            return false;
        }
        $message = '';
        foreach ($state as $key => $value) {
            // one line per field: a newline inside a value would start a field of its own
            $message .= $key . '=' . str_replace(["\r", "\n"], ' ', $value) . "\n";
        }
        $socket = $this->socket();
        if ($socket === null) {
            return false;
        }

        return @fwrite($socket, $message) === strlen($message);
    }

    public function watchdogInterval(): ?float
    {
        return $this->watchdogMicros === null ? null : $this->watchdogMicros / 2e6;
    }

    /**
     * @return resource|null
     */
    private function socket()
    {
        if ($this->socket !== null) {
            return $this->socket;
        }
        $path = str_starts_with((string) $this->address, '@') ? "\0" . substr((string) $this->address, 1) : (string) $this->address;
        $socket = @stream_socket_client('udg://' . $path, $code, $error, 1.0);
        if ($socket === false) {
            // a socket that cannot be reached stays so for this run: do not retry on every message
            $this->broken = true;

            return null;
        }

        return $this->socket = $socket;
    }
}
