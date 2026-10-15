<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Logging;

use Mfonte\Arkhive\Application\Port\EventLog;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Infrastructure\Socket\UnixDatagram;

/**
 * The journal's native protocol (systemd.journal-fields(7), sd_journal_send(3)) in PHP: one
 * datagram per event to journald's socket, "FIELD=value" lines, and the binary form (name, newline,
 * 64-bit little-endian length, value) for a value with a line break.
 */
final class JournalSocket implements EventLog
{
    /** a datagram must fit the socket's send buffer; longer messages are cut (journald's own limit is far above) */
    private const MAX_MESSAGE = 48 * 1024;

    private readonly UnixDatagram $socket;

    public function __construct(?string $path = null, private readonly string $identifier = 'arkhive')
    {
        $this->socket = new UnixDatagram($path ?? (string) LogTarget::Journal->socket());
    }

    public function write(LogLevel $level, string $message, array $fields): bool
    {
        return $this->socket->send(self::serialize([
            'MESSAGE' => strlen($message) > self::MAX_MESSAGE ? substr($message, 0, self::MAX_MESSAGE) . '...' : $message,
            'PRIORITY' => (string) $level->priority(),
            'SYSLOG_IDENTIFIER' => $this->identifier,
        ] + $fields));
    }

    /**
     * @param array<string, string> $fields
     */
    public static function serialize(array $fields): string
    {
        $datagram = '';
        foreach ($fields as $name => $value) {
            if (preg_match('/^[A-Z0-9][A-Z0-9_]{0,63}$/', $name) !== 1) {
                continue;
            }
            $datagram .= str_contains($value, "\n")
                ? $name . "\n" . pack('P', strlen($value)) . $value . "\n"
                : "{$name}={$value}\n";
        }

        return $datagram;
    }
}
