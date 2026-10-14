<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Logging;

use Mfonte\Arkhive\Application\Port\EventLog;
use Mfonte\Arkhive\Application\Port\EventLogs;
use Mfonte\Arkhive\Domain\Execution\LogTarget;
use Mfonte\Arkhive\Domain\Execution\SyslogFacility;
use Mfonte\Arkhive\Domain\Shared\Clock;

final class SocketEventLogs implements EventLogs
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $pid,
        private readonly ?string $journalPath = null,
        private readonly ?string $syslogPath = null,
    ) {
    }

    public function open(LogTarget $target, SyslogFacility $facility): ?EventLog
    {
        return match ($target) {
            LogTarget::Stderr => null,
            LogTarget::Journal => new JournalSocket($this->journalPath),
            LogTarget::Syslog => new SyslogSocket($facility, $this->clock, $this->pid, $this->syslogPath),
        };
    }
}
