<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Output;

use Mfonte\Arkhive\Application\Port\EventLog;
use Mfonte\Arkhive\Application\Port\LogSink;
use Mfonte\Arkhive\Application\Service\ServiceStatus;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\MessageId;
use Mfonte\Arkhive\Domain\Shared\Clock;

/**
 * Every log event goes through here, formatted for the execution context (spec §11).
 * Events go to stderr, so stdout carries only command results (tables, JSON, restored file bytes),
 * or to an event log (the journal, syslog) with structured fields, falling back to stderr when it
 * cannot be reached; a terminal shows them in any case.
 * Under a service manager, each step and the progress within it also become the unit's status.
 */
final class Logger
{
    private const ANSI = [
        'debug' => "\033[90m",
        'success' => "\033[32m",
        'warning' => "\033[33m",
        'error' => "\033[31m",
        'notice' => "\033[36m",
    ];

    private const EMOJI = [
        'debug' => '  ',
        'info' => '• ',
        'success' => '✅ ',
        'notice' => '💡 ',
        'warning' => '⚠️  ',
        'error' => '⛔ ',
    ];

    private bool $progressActive = false;
    private int $warnings = 0;
    private int $errors = 0;
    private string $step = '';

    /** @var array{MessageId, string}|null the event that opened the run, sent again to an event log chosen later (useEvents()) */
    private ?array $opening = null;

    /**
     * @param resource              $stream where events are written (stderr)
     * @param array<string, string> $fields journal fields of every event (ARKHIVE_COMMAND, ARKHIVE_RUN_ID)
     */
    public function __construct(
        private readonly ExecutionContext $context,
        private $stream,
        private readonly ?LogSink $sink,
        private readonly Clock $clock,
        private readonly bool $verbose = false,
        private readonly ?ServiceStatus $service = null,
        private ?EventLog $events = null,
        private readonly array $fields = [],
    ) {
    }

    /**
     * Switches the event log (the configuration chose another target); the run's opening event is
     * sent to the new one.
     */
    public function useEvents(?EventLog $events): void
    {
        $this->events = $events;
        if ($this->opening !== null) {
            $this->event($this->opening[0], LogLevel::Info, $this->opening[1]);
        }
    }

    /**
     * The event that opens a run: only for an event log (stderr and the log file have no message ids).
     */
    public function opening(MessageId $id, string $message): void
    {
        $this->opening = [$id, $message];
        $this->event($id, LogLevel::Info, $message);
    }

    /**
     * An event with a MESSAGE_ID, only for an event log: the start and the outcome of a run.
     *
     * @param array<string, scalar|null> $data
     */
    public function event(MessageId $id, LogLevel $level, string $message, array $data = []): void
    {
        $this->events?->write($level, $message, ['MESSAGE_ID' => $id->value] + $this->eventFields($level, $data));
    }

    public function context(): ExecutionContext
    {
        return $this->context;
    }

    /**
     * @param array<string, scalar|null> $data structured fields, kept by the json format
     */
    public function debug(string $message, array $data = []): void
    {
        $this->log(LogLevel::Debug, $message, $data);
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function info(string $message, array $data = []): void
    {
        $this->log(LogLevel::Info, $message, $data);
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function success(string $message, array $data = []): void
    {
        $this->log(LogLevel::Success, $message, $data);
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function notice(string $message, array $data = []): void
    {
        $this->log(LogLevel::Notice, $message, $data);
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function warning(string $message, array $data = []): void
    {
        $this->warnings++;
        $this->log(LogLevel::Warning, $message, $data);
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function error(string $message, array $data = []): void
    {
        $this->errors++;
        $this->log(LogLevel::Error, $message, $data);
    }

    public function warningCount(): int
    {
        return $this->warnings;
    }

    public function errorCount(): int
    {
        return $this->errors;
    }

    /**
     * Whether progress reports are wanted: a terminal shows them, a service manager makes them the
     * unit's status.
     */
    public function wantsProgress(): bool
    {
        return $this->context->interactive || ($this->service?->supervised() ?? false);
    }

    /**
     * Redraws a single progress line in interactive mode (never in unattended mode); under a service
     * manager, the status of the current step.
     */
    public function progress(string $line): void
    {
        $this->service?->progress(($this->step === '' ? '' : $this->step . ': ') . trim($line));
        if (!$this->context->interactive) {
            return;
        }
        fwrite($this->stream, "\r\033[2K" . $line);
        $this->progressActive = true;
    }

    public function endProgress(): void
    {
        if ($this->progressActive) {
            fwrite($this->stream, "\r\033[2K");
            $this->progressActive = false;
        }
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function log(LogLevel $level, string $message, array $data = []): void
    {
        if ($level === LogLevel::Debug && !$this->verbose) {
            return;
        }

        $this->endProgress();
        if (in_array($level, [LogLevel::Info, LogLevel::Success, LogLevel::Error], true)) {
            $this->step = $level === LogLevel::Info ? $message : '';
            $this->service?->step($message);
        }
        $delivered = $this->events !== null && $this->events->write($level, $message, $this->eventFields($level, $data));
        if (!$delivered || $this->context->interactive) {
            fwrite($this->stream, $this->format($this->context->logFormat, $level, $message, $data) . "\n");
        }

        if ($this->sink !== null) {
            $fileFormat = $this->context->logFormat === LogFormat::Json ? LogFormat::Json : LogFormat::Plain;
            $this->sink->write($this->format($fileFormat, $level, $message, $data));
        }
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function format(LogFormat $format, LogLevel $level, string $message, array $data = []): string
    {
        return match ($format) {
            LogFormat::Human => $this->human($level, $message),
            LogFormat::Plain => $this->clock->now()->format('Y-m-d\TH:i:sP') . ' ' . $level->value . ' ' . self::oneLine($message),
            LogFormat::Journald => '<' . $level->priority() . '>' . self::oneLine($message),
            LogFormat::Json => json_encode(
                ['time' => $this->clock->now()->format('Y-m-d\TH:i:sP'), 'level' => $level->value, 'message' => $message] + $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ),
        };
    }

    /**
     * The journal fields of an event: the run's, the level (success is a priority 6 like info), the
     * current step and the event's own data as ARKHIVE_<KEY>.
     *
     * @param array<string, scalar|null> $data
     *
     * @return array<string, string>
     */
    private function eventFields(LogLevel $level, array $data): array
    {
        $fields = $this->fields + ['ARKHIVE_LEVEL' => $level->value];
        if ($this->step !== '') {
            $fields['ARKHIVE_PHASE'] = $this->step;
        }
        foreach ($data as $key => $value) {
            $name = 'ARKHIVE_' . substr(trim((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper($key)), '_'), 0, 56);
            if ($value !== null && $name !== 'ARKHIVE_') {
                $fields[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $fields;
    }

    private function human(LogLevel $level, string $message): string
    {
        $text = self::EMOJI[$level->value] . $message;
        if (!$this->context->colour || !isset(self::ANSI[$level->value])) {
            return $text;
        }

        return self::ANSI[$level->value] . $text . "\033[0m";
    }

    private static function oneLine(string $message): string
    {
        $lines = preg_split('/\R/', trim($message)) ?: [];

        return implode(' | ', array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }
}
