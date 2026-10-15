<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Logging;

use Mfonte\Arkhive\Application\Port\EventLog;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Infrastructure\Socket\UnixDatagram;

/**
 * The local syslog daemon (rsyslog, syslog-ng, busybox syslogd, or journald behind /dev/log), in
 * the format the C library's syslog(3) sends: "<PRI>Mmm dd hh:mm:ss arkhive[PID]: message", with
 * the structured fields appended as key=value.
 */
final class SyslogSocket implements EventLog
{
    /** rsyslog's default maximum message size */
    private const MAX_LENGTH = 8096;

    /** fields left out of the line: the level is the PRI, the phase repeats an earlier message */
    private const SKIPPED = ['ARKHIVE_LEVEL', 'ARKHIVE_PHASE'];

    private readonly UnixDatagram $socket;

    public function __construct(
        private readonly SyslogFacility $facility,
        private readonly Clock $clock,
        private readonly int $pid,
        ?string $path = null,
        private readonly string $identifier = 'arkhive',
    ) {
        $this->socket = new UnixDatagram($path ?? (string) LogTarget::Syslog->socket());
    }

    public function write(LogLevel $level, string $message, array $fields): bool
    {
        $line = '<' . $this->facility->priority($level) . '>' . $this->clock->now()->format('M j H:i:s') . " {$this->identifier}[{$this->pid}]: "
            . self::oneLine($message) . self::pairs($fields);
        // the C library pads the day to two characters: "Oct  4"
        $line = (string) preg_replace('/^(<\d+>[A-Z][a-z]{2}) (\d) /', '$1  $2 ', $line);

        return $this->socket->send(strlen($line) > self::MAX_LENGTH ? substr($line, 0, self::MAX_LENGTH) : $line);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function pairs(array $fields): string
    {
        $text = '';
        foreach ($fields as $name => $value) {
            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }
            $key = strtolower(str_starts_with($name, 'ARKHIVE_') ? substr($name, 8) : $name);
            $value = self::oneLine($value);
            $text .= " {$key}=" . (preg_match('/^[^\s"=]+$/', $value) === 1 ? $value : '"' . addcslashes($value, '"\\') . '"');
        }

        return $text;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s*\R\s*/', ' | ', trim($text)));
    }
}
