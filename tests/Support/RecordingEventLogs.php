<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\EventLog;
use Mfonte\Arkhive\Application\Port\EventLogs;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;

/**
 * The journal and syslog as a list of the events they received.
 */
final class RecordingEventLogs implements EventLogs, EventLog
{
    /** @var list<array{target: string, level: string, message: string, fields: array<string, string>}> */
    public array $events = [];

    /** @var list<string> "journal", "syslog/local3": each target opened */
    public array $opened = [];

    private string $current = '';

    /**
     * @param bool $reachable false: every write fails, as with no journald or syslog daemon
     */
    public function __construct(public bool $reachable = true)
    {
    }

    public function open(LogTarget $target, SyslogFacility $facility): ?EventLog
    {
        if ($target === LogTarget::Stderr) {
            return null;
        }
        $this->current = $target === LogTarget::Syslog ? "syslog/{$facility->value}" : $target->value;
        $this->opened[] = $this->current;

        return $this;
    }

    public function write(LogLevel $level, string $message, array $fields): bool
    {
        if (!$this->reachable) {
            return false;
        }
        $this->events[] = ['target' => $this->current, 'level' => $level->value, 'message' => $message, 'fields' => $fields];

        return true;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_column($this->events, 'message');
    }

    /**
     * @return array{target: string, level: string, message: string, fields: array<string, string>}|null
     */
    public function withMessageId(string $id): ?array
    {
        foreach ($this->events as $event) {
            if (($event['fields']['MESSAGE_ID'] ?? '') === $id) {
                return $event;
            }
        }

        return null;
    }
}
