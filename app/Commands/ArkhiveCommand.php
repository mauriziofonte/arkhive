<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\EventLogs;
use Mfonte\Arkhive\Application\Port\Terminal;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Service\ServiceStatus;
use Mfonte\Arkhive\Commands\Support\UsageErrors;
use Mfonte\Arkhive\Domain\Execution\ContextDetector;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\MessageId;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Logging\LogFile;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use Mfonte\Arkhive\Infrastructure\Process\Signals;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Base of every 2.0 command: detects the execution context once, builds the logger, and maps
 * exceptions to the stable exit codes. Works before any configuration is loaded.
 */
abstract class ArkhiveCommand extends Command
{
    protected ExecutionContext $context;
    protected Logger $log;
    protected Signals $interrupts;
    protected Cleanup $cleanup;
    protected Terminal $terminal;
    protected Clock $clock;
    protected ServiceStatus $service;
    private EventLogs $eventLogs;
    private SyslogFacility $facility = SyslogFacility::Daemon;

    public function __construct()
    {
        parent::__construct();

        $this->addOption('interactive', null, InputOption::VALUE_NONE, 'Force interactive mode (needs a terminal)');
        $this->addOption('log-format', null, InputOption::VALUE_REQUIRED, 'Log format: text or json', 'text');
        $this->addOption('log-file', null, InputOption::VALUE_REQUIRED, 'Also append log lines to this file (rotated by size)');
        $this->addOption('log-target', null, InputOption::VALUE_REQUIRED, 'Where log events go: auto (the journal when stderr is connected to it), stderr, journal or syslog');
    }

    /**
     * Options and arguments are bound before handle(): an unknown option, a missing or an extra
     * argument is a usage error (exit 64), not the console's generic failure (exit 1).
     */
    #[\Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $e) {
            return UsageErrors::report($e->getMessage(), "See the options with: arkhive {$this->getName()} --help");
        }
    }

    final public function handle(Terminal $terminal, LogStream $stream, Clock $clock, Signals $signals, Cleanup $cleanup, ServiceStatus $service, EventLogs $eventLogs): int
    {
        $this->service = $service;
        $this->eventLogs = $eventLogs;
        $this->facility = SyslogFacility::Daemon;
        $this->interrupts = $signals;
        $this->cleanup = $cleanup;
        $this->terminal = $terminal;
        $this->clock = $clock;
        try {
            $this->context = ContextDetector::detect(
                $terminal->environment(),
                $terminal->stdinIsTty(),
                $terminal->stdoutIsTty(),
                $this->input->hasParameterOption(['--no-interaction', '-n'], true),
                (bool) $this->option('interactive'),
                self::stringOption($this->option('log-format')),
            );
            $target = self::stringOption($this->option('log-target'));
            if ($target !== null && !LogTarget::isName($target)) {
                throw new UsageException("Invalid --log-target \"{$target}\": use " . LogTarget::CHOICES);
            }
            $this->context = $this->context->withLogTarget(...LogTarget::choose($target, null, $terminal->environment(), $terminal->stderrIdentity()));
        } catch (UsageException $e) {
            $this->context = new ExecutionContext(false, false, false, LogFormat::Plain, 'usage error');
            $this->log = new Logger($this->context, $stream->resource(), null, $clock);
            $this->log->error($e->getMessage());

            return $e->exitCode();
        }

        $this->input->setInteractive($this->context->interactive);
        $this->output->setDecorated($this->context->colour);

        try {
            $logFile = self::stringOption($this->option('log-file'));
            $file = $logFile === null ? null : new LogFile($logFile);
        } catch (ArkhiveException $e) {
            $file = null;
            $this->log = new Logger($this->context, $stream->resource(), null, $clock);
            $this->log->error($e->getMessage());

            return $e->exitCode();
        }

        $fields = ['ARKHIVE_COMMAND' => (string) $this->getName(), 'ARKHIVE_RUN_ID' => bin2hex(random_bytes(16)), 'ARKHIVE_VERSION' => (string) config('app.version')];
        $events = $eventLogs->open($this->context->logTarget, $this->facility);
        $this->log = new Logger($this->context, $stream->resource(), $file, $clock, $this->output->isVerbose(), $service, $events, $fields);
        $service->started("arkhive {$this->getName()} started");
        $opening = MessageId::forRun((string) $this->getName(), null);
        if ($opening !== null) {
            $this->log->opening($opening, "arkhive {$this->getName()} started");
        }

        $installed = $signals->installed() || !$signals->install();
        $started = $clock->now();
        $failure = null;
        try {
            try {
                $exit = $this->perform();
            } catch (ArkhiveException $e) {
                $this->log->error($e->getMessage());
                if ($e->hint() !== null) {
                    $this->log->notice($e->hint());
                }
                $exit = $e->exitCode();
                $failure = $e;
            } catch (Throwable $e) {
                $this->log->error(sprintf('Unexpected error: %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));
                $exit = ExitCode::SOFTWARE;
                $failure = $e;
            }
            $this->finished($exit, $failure, $started);
            $outcome = $this->outcome($exit, $failure);
            $service->finished($exit, $outcome);
            $closing = MessageId::forRun((string) $this->getName(), $exit);
            if ($closing !== null) {
                $level = match ($exit) {
                    ExitCode::SUCCESS => LogLevel::Success,
                    ExitCode::WARNING => LogLevel::Warning,
                    default => LogLevel::Error,
                };
                $this->log->event($closing, $level, $outcome, ['exit_status' => $exit] + $this->outcomeData());
            }

            return $exit;
        } finally {
            foreach ($cleanup->runAll() as $cleanupFailure) {
                $this->log->warning("Cleanup failed: {$cleanupFailure}");
            }
            if (!$installed) {
                $signals->uninstall();
            }
        }
    }

    /**
     * Called once the command has ended, with its exit code, before the cleanup (the lock is still
     * held). Must not throw.
     */
    protected function finished(int $exit, ?Throwable $failure, DateTimeImmutable $started): void
    {
    }

    /**
     * What the event closing a run reports besides its exit status (ConfiguredCommand: the snapshot).
     *
     * @return array<string, scalar|null>
     */
    protected function outcomeData(): array
    {
        return [];
    }

    /**
     * The configuration's LOG_TARGET and SYSLOG_FACILITY, once it is loaded: --log-target still
     * wins; a different target (or facility) replaces the event log from here on.
     */
    protected function configureLogging(?LogTarget $configured, SyslogFacility $facility): void
    {
        [$target, $reason] = LogTarget::choose(self::stringOption($this->option('log-target')), $configured, $this->terminal->environment(), $this->terminal->stderrIdentity());
        if ($target === $this->context->logTarget && ($facility === $this->facility || $target !== LogTarget::Syslog)) {
            return;
        }
        $this->facility = $facility;
        $this->context = $this->context->withLogTarget($target, $reason);
        $this->log->useEvents($this->eventLogs->open($target, $facility));
    }

    /**
     * "arkhive backup failed with exit 69 (UNAVAILABLE): ...", for the service manager's status.
     */
    private function outcome(int $exit, ?Throwable $failure): string
    {
        $name = "arkhive {$this->getName()}";
        if ($exit === ExitCode::SUCCESS) {
            return "{$name} succeeded";
        }
        if ($exit === ExitCode::WARNING) {
            return "{$name} succeeded with warnings";
        }
        $label = isset(ExitCode::TABLE[$exit]) ? ' (' . ExitCode::TABLE[$exit][0] . ')' : '';

        return "{$name} failed with exit {$exit}{$label}" . ($failure === null ? '' : ': ' . $failure->getMessage());
    }

    /**
     * The command itself; returns an ExitCode constant.
     */
    abstract protected function perform(): int;

    /**
     * Writes a command result to stdout (logs go to stderr).
     */
    protected function result(string $text): void
    {
        $this->output->writeln($text, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
    }

    protected static function stringOption(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
