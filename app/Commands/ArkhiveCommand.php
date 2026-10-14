<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use LaravelZero\Framework\Commands\Command;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\Terminal;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Commands\Support\UsageErrors;
use Mfonte\Arkhive\Domain\Execution\ContextDetector;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
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

    public function __construct()
    {
        parent::__construct();

        $this->addOption('interactive', null, InputOption::VALUE_NONE, 'Force interactive mode (needs a terminal)');
        $this->addOption('log-format', null, InputOption::VALUE_REQUIRED, 'Log format: text or json', 'text');
        $this->addOption('log-file', null, InputOption::VALUE_REQUIRED, 'Also append log lines to this file (rotated by size)');
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

    final public function handle(Terminal $terminal, LogStream $stream, Clock $clock, Signals $signals, Cleanup $cleanup): int
    {
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

        $this->log = new Logger($this->context, $stream->resource(), $file, $clock, $this->output->isVerbose());

        $installed = $signals->installed() || !$signals->install();
        try {
            return $this->perform();
        } catch (ArkhiveException $e) {
            $this->log->error($e->getMessage());
            if ($e->hint() !== null) {
                $this->log->notice($e->hint());
            }

            return $e->exitCode();
        } catch (Throwable $e) {
            $this->log->error(sprintf('Unexpected error: %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return ExitCode::SOFTWARE;
        } finally {
            foreach ($cleanup->runAll() as $failure) {
                $this->log->warning("Cleanup failed: {$failure}");
            }
            if (!$installed) {
                $signals->uninstall();
            }
        }
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
