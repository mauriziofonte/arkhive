<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Output;

use Mfonte\Arkhive\Services\Context\ExecutionContext;
use Mfonte\Arkhive\Services\Context\LogFormat;
use Mfonte\Arkhive\Services\Output\LogFile;
use Mfonte\Arkhive\Services\Output\Logger;
use Mfonte\Arkhive\Services\Output\LogLevel;
use Mfonte\Arkhive\Services\Output\LogStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
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
