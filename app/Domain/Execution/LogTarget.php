<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Execution;

/**
 * Where log events go besides an interactive terminal: stderr (the 2.0 behaviour), the systemd
 * journal (native protocol, structured fields) or the local syslog daemon.
 */
enum LogTarget: string
{
    case Stderr = 'stderr';
    case Journal = 'journal';
    case Syslog = 'syslog';

    /** chooses journal when stderr is connected to the journal, stderr otherwise */
    public const AUTO = 'auto';

    public const CHOICES = 'auto, stderr, journal or syslog';

    /**
     * The datagram socket events are sent to; null for stderr.
     */
    public function socket(): ?string
    {
        return match ($this) {
            self::Stderr => null,
            self::Journal => '/run/systemd/journal/socket',
            self::Syslog => '/dev/log',
        };
    }

    public static function isName(string $value): bool
    {
        return $value === self::AUTO || self::tryFrom($value) !== null;
    }

    /**
     * The target and why it was chosen.
     *
     * @param string|null           $option         --log-target as given (null: not given)
     * @param self|null             $configured     LOG_TARGET of the configuration (null: auto or unset)
     * @param array<string, string> $env
     * @param string|null           $stderrIdentity "device:inode" of this process's stderr
     *
     * @return array{self, string}
     */
    public static function choose(?string $option, ?self $configured, array $env, ?string $stderrIdentity): array
    {
        if ($option !== null && $option !== self::AUTO) {
            return [self::from($option), '--log-target'];
        }
        if ($option === null && $configured !== null) {
            return [$configured, 'LOG_TARGET'];
        }
        if (self::stderrIsJournal($env['JOURNAL_STREAM'] ?? '', $stderrIdentity)) {
            return [self::Journal, 'stderr is the journal'];
        }

        return [self::Stderr, 'default'];
    }

    /**
     * systemd sets $JOURNAL_STREAM to "device:inode" of the stream it connected to the journal; it is
     * inherited, so it only counts when stderr is still that stream (not redirected to a file or pipe).
     */
    public static function stderrIsJournal(string $journalStream, ?string $stderrIdentity): bool
    {
        return $stderrIdentity !== null && preg_match('/^\d+:\d+$/', $journalStream) === 1 && $journalStream === $stderrIdentity;
    }
}
