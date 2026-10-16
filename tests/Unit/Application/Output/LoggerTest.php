<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Output;

use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Service\ServiceStatus;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\MessageId;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Infrastructure\Logging\LogFile;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\RecordingEventLogs;
use Tests\Support\RecordingServiceManager;
use Tests\Support\TempDir;

final class LoggerTest extends TestCase
{
    use TempDir;

    private static function context(LogFormat $format, bool $interactive = false, bool $colour = false): ExecutionContext
    {
        return new ExecutionContext($interactive, $format === LogFormat::Journald, $colour, $format, 'test');
    }

    private function logger(ExecutionContext $context, LogStream $stream, ?LogFile $file = null, bool $verbose = false): Logger
    {
        return new Logger($context, $stream->resource(), $file, new FixedClock(), $verbose);
    }

    public function testPlainLinesCarryAnIsoTimestampAndTheLevel(): void
    {
        $stream = LogStream::memory();
        $log = $this->logger(self::context(LogFormat::Plain), $stream);

        $log->info('backup started');
        $log->warning('3 files unreadable');
        $log->error("restic failed:\nFatal: wrong password");

        self::assertSame(
            "2026-10-12T10:20:30+02:00 info backup started\n"
            . "2026-10-12T10:20:30+02:00 warning 3 files unreadable\n"
            . "2026-10-12T10:20:30+02:00 error restic failed: | Fatal: wrong password\n",
            $stream->contents(),
        );
    }

    /**
     * @return iterable<string, array{LogLevel, string}>
     */
    public static function priorities(): iterable
    {
        yield 'error' => [LogLevel::Error, '<3>'];
        yield 'warning' => [LogLevel::Warning, '<4>'];
        yield 'notice' => [LogLevel::Notice, '<5>'];
        yield 'info' => [LogLevel::Info, '<6>'];
        yield 'success' => [LogLevel::Success, '<6>'];
        yield 'debug' => [LogLevel::Debug, '<7>'];
    }

    #[DataProvider('priorities')]
    public function testJournaldLinesHaveSyslogPrioritiesAndNoTimestamp(LogLevel $level, string $prefix): void
    {
        $stream = LogStream::memory();
        $this->logger(self::context(LogFormat::Journald), $stream, null, true)->log($level, 'message');

        self::assertSame("{$prefix}message\n", $stream->contents());
    }

    public function testJsonLinesKeepStructuredData(): void
    {
        $stream = LogStream::memory();
        $this->logger(self::context(LogFormat::Json), $stream)->success('backup done', ['snapshot_id' => 'abc', 'files' => 3]);

        $event = json_decode(trim($stream->contents()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['time' => '2026-10-12T10:20:30+02:00', 'level' => 'success', 'message' => 'backup done', 'snapshot_id' => 'abc', 'files' => 3],
            $event,
        );
    }

    public function testHumanLinesHaveEmojiAndColourOnlyWhenAllowed(): void
    {
        $coloured = LogStream::memory();
        $this->logger(self::context(LogFormat::Human, true, true), $coloured)->error('boom');
        self::assertSame("\033[31m⛔ boom\033[0m\n", $coloured->contents());

        $plain = LogStream::memory();
        $log = $this->logger(self::context(LogFormat::Human, true, false), $plain);
        $log->error('boom');
        $log->info('fine');
        self::assertSame("⛔ boom\n• fine\n", $plain->contents());
    }

    public function testHumanInfoIsNeverColoured(): void
    {
        $stream = LogStream::memory();
        $this->logger(self::context(LogFormat::Human, true, true), $stream)->info('fine');

        self::assertSame("• fine\n", $stream->contents());
    }

    public function testDebugIsShownOnlyWhenVerbose(): void
    {
        $quiet = LogStream::memory();
        $this->logger(self::context(LogFormat::Plain), $quiet)->debug('details');
        self::assertSame('', $quiet->contents());

        $verbose = LogStream::memory();
        $this->logger(self::context(LogFormat::Plain), $verbose, null, true)->debug('details');
        self::assertSame("2026-10-12T10:20:30+02:00 debug details\n", $verbose->contents());
    }

    public function testProgressIsDrawnOnlyInInteractiveModeAndClearedBeforeTheNextEvent(): void
    {
        $unattended = LogStream::memory();
        $log = $this->logger(self::context(LogFormat::Plain), $unattended);
        $log->progress('42%');
        $log->endProgress();
        self::assertSame('', $unattended->contents());

        $interactive = LogStream::memory();
        $log = $this->logger(self::context(LogFormat::Human, true), $interactive);
        $log->progress('42%');
        $log->info('done');
        $log->endProgress();
        self::assertSame("\r\033[2K42%\r\033[2K• done\n", $interactive->contents());
    }

    public function testUnderAServiceManagerStepsAndProgressBecomeTheUnitStatus(): void
    {
        $manager = new RecordingServiceManager();
        $time = 0.0;
        $service = new ServiceStatus($manager, static function () use (&$time): float {
            return $time += 10.0;
        });
        $stream = LogStream::memory();
        $log = new Logger(self::context(LogFormat::Journald), $stream->resource(), null, new FixedClock(), false, $service);

        self::assertTrue($log->wantsProgress(), 'unattended, but systemd shows the progress');
        $log->info('Backing up /srv /etc');
        $log->progress('  42.0%  120/300 files  1.20 GiB/3.10 GiB');
        $log->debug('not a step');
        $log->notice('nor a hint');
        $log->warning('nor a warning');
        $log->success('Snapshot 6118b3c5 saved');
        $log->progress('100.0%');
        $log->error('restic check failed');

        self::assertSame([
            'Backing up /srv /etc',
            'Backing up /srv /etc: 42.0% 120/300 files 1.20 GiB/3.10 GiB',
            'Snapshot 6118b3c5 saved',
            '100.0%',
            'restic check failed',
        ], $manager->statuses());
        self::assertStringNotContainsString('42.0%', $stream->contents(), 'never drawn when unattended');
        self::assertFalse($this->logger(self::context(LogFormat::Journald), LogStream::memory())->wantsProgress());
        self::assertTrue($this->logger(self::context(LogFormat::Human, interactive: true), LogStream::memory())->wantsProgress());
    }

    public function testAnEventLogReplacesStderrAndKeepsStructuredFields(): void
    {
        $stream = LogStream::memory();
        $events = new RecordingEventLogs();
        $log = new Logger(self::context(LogFormat::Journald), $stream->resource(), null, new FixedClock(), false, null, $events->open(LogTarget::Journal, SyslogFacility::Daemon), ['ARKHIVE_COMMAND' => 'backup', 'ARKHIVE_RUN_ID' => 'r1']);

        $log->info('Backing up /srv');
        $log->warning('Skipped a file', ['path' => '/srv/a b', 'snapshot-id' => 'e82340a7', 'retried' => false, 'size' => 12, 'none' => null, '!!!' => 'dropped']);
        $log->success('Backup done', ['ok' => true]);

        self::assertSame('', $stream->contents(), 'delivered events are not written to stderr too');
        self::assertSame(['Backing up /srv', 'Skipped a file', 'Backup done'], $events->messages());
        self::assertSame(['ARKHIVE_COMMAND' => 'backup', 'ARKHIVE_RUN_ID' => 'r1', 'ARKHIVE_LEVEL' => 'info', 'ARKHIVE_PHASE' => 'Backing up /srv'], $events->events[0]['fields']);
        self::assertSame([
            'ARKHIVE_COMMAND' => 'backup',
            'ARKHIVE_RUN_ID' => 'r1',
            'ARKHIVE_LEVEL' => 'warning',
            'ARKHIVE_PHASE' => 'Backing up /srv',
            'ARKHIVE_PATH' => '/srv/a b',
            'ARKHIVE_SNAPSHOT_ID' => 'e82340a7',
            'ARKHIVE_RETRIED' => 'false',
            'ARKHIVE_SIZE' => '12',
        ], $events->events[1]['fields']);
        self::assertSame(['ARKHIVE_COMMAND' => 'backup', 'ARKHIVE_RUN_ID' => 'r1', 'ARKHIVE_LEVEL' => 'success', 'ARKHIVE_OK' => 'true'], $events->events[2]['fields'], 'a step ends with success');
        self::assertSame(['info', 'warning', 'success'], array_column($events->events, 'level'));
        self::assertSame(1, $log->warningCount());
    }

    public function testAnUnreachableEventLogFallsBackToStderr(): void
    {
        $stream = LogStream::memory();
        $events = new RecordingEventLogs(reachable: false);
        $log = new Logger(self::context(LogFormat::Journald), $stream->resource(), null, new FixedClock(), false, null, $events->open(LogTarget::Journal, SyslogFacility::Daemon));

        $log->error('restic failed');

        self::assertSame("<3>restic failed\n", $stream->contents());
    }

    public function testATerminalStillShowsTheEvents(): void
    {
        $stream = LogStream::memory();
        $events = new RecordingEventLogs();
        $log = new Logger(self::context(LogFormat::Human, interactive: true), $stream->resource(), null, new FixedClock(), false, null, $events->open(LogTarget::Syslog, SyslogFacility::Daemon));

        $log->info('Restoring');

        self::assertSame("• Restoring\n", $stream->contents());
        self::assertSame(['Restoring'], $events->messages());
    }

    public function testRunEventsGoOnlyToTheEventLogWithTheirMessageId(): void
    {
        $stream = LogStream::memory();
        $path = $this->tempDir() . '/arkhive.log';
        $log = new Logger(self::context(LogFormat::Plain), $stream->resource(), new LogFile($path), new FixedClock(), false, null, null, ['ARKHIVE_COMMAND' => 'backup']);

        $log->opening(MessageId::BackupStarted, 'arkhive backup started');
        $log->event(MessageId::BackupFailed, LogLevel::Error, 'arkhive backup failed with exit 69 (UNAVAILABLE)', ['exit_status' => 69]);
        self::assertSame('', $stream->contents(), 'stderr has no message ids');

        // the configuration chose syslog: the opening event is sent there, then everything else
        $events = new RecordingEventLogs();
        $log->useEvents($events->open(LogTarget::Syslog, SyslogFacility::Local3));
        $log->event(MessageId::BackupSucceeded, LogLevel::Success, 'arkhive backup succeeded', ['exit_status' => 0, 'snapshot' => 'e82340a7']);

        self::assertSame(['arkhive backup started', 'arkhive backup succeeded'], $events->messages());
        self::assertSame(['syslog/local3', 'syslog/local3'], array_column($events->events, 'target'));
        self::assertSame(['MESSAGE_ID' => MessageId::BackupStarted->value, 'ARKHIVE_COMMAND' => 'backup', 'ARKHIVE_LEVEL' => 'info'], $events->events[0]['fields']);
        self::assertSame(['MESSAGE_ID' => MessageId::BackupSucceeded->value, 'ARKHIVE_COMMAND' => 'backup', 'ARKHIVE_LEVEL' => 'success', 'ARKHIVE_EXIT_STATUS' => '0', 'ARKHIVE_SNAPSHOT' => 'e82340a7'], $events->events[1]['fields']);
        self::assertSame('', $stream->contents());
        self::assertSame('', (string) @file_get_contents($path), 'nor does the log file');

        $log->useEvents(null);
        $log->info('back on stderr');
        self::assertStringEndsWith(" info back on stderr\n", $stream->contents());
        self::assertCount(2, $events->events);
    }

    public function testAReaderThatWentAwayLosesTheLinesButNeverFailsTheRun(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$stderr, $reader] = $pair;
        fclose($reader);
        $path = $this->tempDir() . '/arkhive.log';
        $log = new Logger(self::context(LogFormat::Human, interactive: true), $stderr, new LogFile($path), new FixedClock(), false);

        // a pipe closed early makes each write fail; at run time Laravel reports
        // every level and turns a PHP notice into an exception, so none may be raised
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $log->info('Backing up /srv/data');
            $log->progress('12%');
            $log->success('arkhive backup succeeded');
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }

        self::assertStringContainsString('arkhive backup succeeded', (string) file_get_contents($path), 'the log file still gets every line');
        fclose($stderr);
    }

    public function testCountsWarningsAndErrors(): void
    {
        $log = $this->logger(self::context(LogFormat::Plain), LogStream::memory());
        $log->warning('a');
        $log->warning('b');
        $log->error('c');
        $log->notice('d');

        self::assertSame(2, $log->warningCount());
        self::assertSame(1, $log->errorCount());
        self::assertSame('test', $log->context()->reason);
    }

    public function testTheLogFileGetsPlainLinesEvenInHumanMode(): void
    {
        $file = $this->tempDir() . '/arkhive.log';
        $log = $this->logger(self::context(LogFormat::Human, true, true), LogStream::memory(), new LogFile($file));
        $log->warning('disk almost full');

        self::assertSame("2026-10-12T10:20:30+02:00 warning disk almost full\n", file_get_contents($file));
    }

    public function testTheLogFileGetsJsonLinesInJsonMode(): void
    {
        $file = $this->tempDir() . '/arkhive.log';
        $this->logger(self::context(LogFormat::Json), LogStream::memory(), new LogFile($file))->info('x');

        self::assertSame('{"time":"2026-10-12T10:20:30+02:00","level":"info","message":"x"}' . "\n", file_get_contents($file));
    }

    public function testBlankLinesAreDroppedFromSingleLineFormats(): void
    {
        $log = $this->logger(self::context(LogFormat::Plain), LogStream::memory());

        self::assertSame('<4>a | b', $log->format(LogFormat::Journald, LogLevel::Warning, "\n a \r\n\n  b  \n"));
    }
}
