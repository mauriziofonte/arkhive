<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Output;

use Mfonte\Arkhive\Application\Port\LogSink;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Shared\Clock;

/**
 * Every log event goes through here, formatted for the execution context (spec §11).
 * Events go to stderr, so stdout carries only command results (tables, JSON, restored file bytes).
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

    /**
     * @param resource $stream where events are written (stderr)
     */
    public function __construct(
        private readonly ExecutionContext $context,
        private $stream,
        private readonly ?LogSink $sink,
        private readonly Clock $clock,
        private readonly bool $verbose = false,
    ) {
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
     * Redraws a single progress line; interactive mode only, never in unattended mode.
     */
    public function progress(string $line): void
    {
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
        fwrite($this->stream, $this->format($this->context->logFormat, $level, $message, $data) . "\n");

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
