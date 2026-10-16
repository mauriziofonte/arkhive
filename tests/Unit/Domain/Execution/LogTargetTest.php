<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Execution;

use Mfonte\Arkhive\Domain\Execution\LogLevel;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, LogTarget|null, array<string, string>, string|null, LogTarget, string}>
     */
    public static function choices(): iterable
    {
        $journal = ['JOURNAL_STREAM' => '8:12345'];
        yield 'nothing: stderr' => [null, null, [], '8:12345', LogTarget::Stderr, 'default'];
        yield 'stderr is the journal' => [null, null, $journal, '8:12345', LogTarget::Journal, 'stderr is the journal'];
        yield 'stderr redirected away from the journal' => [null, null, $journal, '8:99', LogTarget::Stderr, 'default'];
        yield 'stderr unknown' => [null, null, $journal, null, LogTarget::Stderr, 'default'];
        yield 'a malformed JOURNAL_STREAM' => [null, null, ['JOURNAL_STREAM' => 'x'], 'x', LogTarget::Stderr, 'default'];
        yield 'the configuration' => [null, LogTarget::Syslog, $journal, '8:12345', LogTarget::Syslog, 'LOG_TARGET'];
        yield 'the option wins' => ['stderr', LogTarget::Syslog, $journal, '8:12345', LogTarget::Stderr, '--log-target'];
        yield 'an explicit auto ignores the configuration' => ['auto', LogTarget::Syslog, $journal, '8:12345', LogTarget::Journal, 'stderr is the journal'];
        yield 'journal asked for' => ['journal', null, [], null, LogTarget::Journal, '--log-target'];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('choices')]
    public function testChoose(?string $option, ?LogTarget $configured, array $env, ?string $stderr, LogTarget $target, string $reason): void
    {
        self::assertSame([$target, $reason], LogTarget::choose($option, $configured, $env, $stderr));
    }

    public function testNamesAndSockets(): void
    {
        self::assertTrue(LogTarget::isName('auto'));
        self::assertTrue(LogTarget::isName('syslog'));
        self::assertFalse(LogTarget::isName('file'));
        self::assertFalse(LogTarget::isName(''));
        self::assertNull(LogTarget::Stderr->socket());
        self::assertSame('/run/systemd/journal/socket', LogTarget::Journal->socket());
        self::assertSame('/dev/log', LogTarget::Syslog->socket());
    }

    public function testSyslogFacilities(): void
    {
        self::assertSame([1, 3, 16, 23], [SyslogFacility::User->code(), SyslogFacility::Daemon->code(), SyslogFacility::Local0->code(), SyslogFacility::Local7->code()]);
        self::assertSame(27, SyslogFacility::Daemon->priority(LogLevel::Error));
        self::assertSame(158, SyslogFacility::Local3->priority(LogLevel::Info));
        self::assertSame(158, SyslogFacility::Local3->priority(LogLevel::Success));
    }
}
