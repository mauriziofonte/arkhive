<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Infrastructure\Logging\JournalSocket;
use Mfonte\Arkhive\Infrastructure\Logging\SocketEventLogs;
use Mfonte\Arkhive\Infrastructure\Logging\SyslogSocket;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;

final class SocketEventLogsTest extends TestCase
{
    public function testEachTargetOpensItsSocket(): void
    {
        $logs = new SocketEventLogs(new FixedClock(), 4242);

        self::assertNull($logs->open(LogTarget::Stderr, SyslogFacility::Daemon));
        self::assertInstanceOf(JournalSocket::class, $logs->open(LogTarget::Journal, SyslogFacility::Daemon));
        self::assertInstanceOf(SyslogSocket::class, $logs->open(LogTarget::Syslog, SyslogFacility::Local1));
    }
}
