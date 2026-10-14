<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Schedule;

use Mfonte\Arkhive\ExitCode;

/**
 * How a systemd service last ended, from `systemctl show`: its result (exit-code, timeout, signal,
 * oom-kill...), the main process status and the invocation it belongs to.
 */
final readonly class UnitState
{
    /** ExecMainCode values (waitid(2) si_code) */
    private const EXITED = 1;
    private const KILLED = 2;
    private const DUMPED = 3;

    /** the `systemctl show` properties fromProperties() reads */
    public const PROPERTIES = ['Id', 'LoadState', 'Result', 'ExecMainCode', 'ExecMainStatus', 'InvocationID', 'ExecMainStartTimestamp', 'ExecMainExitTimestamp'];

    private const SIGNALS = [1 => 'HUP', 2 => 'INT', 3 => 'QUIT', 6 => 'ABRT', 9 => 'KILL', 11 => 'SEGV', 13 => 'PIPE', 15 => 'TERM'];

    public function __construct(
        public string $unit,
        public string $result,
        public int $mainCode,
        public int $mainStatus,
        public ?string $invocationId,
        public string $started = '',
        public string $ended = '',
    ) {
    }

    /**
     * @param array<string, string> $properties `systemctl show` output, Key => Value
     */
    public static function fromProperties(string $unit, array $properties): self
    {
        $invocation = strtolower($properties['InvocationID'] ?? '');

        return new self(
            $properties['Id'] ?? $unit,
            $properties['Result'] ?? '',
            (int) ($properties['ExecMainCode'] ?? 0),
            (int) ($properties['ExecMainStatus'] ?? 0),
            preg_match('/^[0-9a-f]{32}$/', $invocation) === 1 ? $invocation : null,
            self::timestamp($properties['ExecMainStartTimestamp'] ?? ''),
            self::timestamp($properties['ExecMainExitTimestamp'] ?? ''),
        );
    }

    /**
     * A `systemctl show` timestamp; never set reads '' (older systemd prints n/a, or 0 for a raw value).
     */
    public static function timestamp(string $value): string
    {
        return in_array($value, ['', 'n/a', '0'], true) ? '' : $value;
    }

    /**
     * The job of an ArkHive unit (arkhive-backup.service: backup), or null for any other unit.
     */
    public function job(): ?Job
    {
        return preg_match('/^arkhive-([a-z]+)\.service$/', $this->unit, $match) === 1 ? Job::tryFrom($match[1]) : null;
    }

    /**
     * "exit 69 (UNAVAILABLE)", "killed by signal 9 (KILL), result timeout".
     */
    public function describe(): string
    {
        $how = match ($this->mainCode) {
            self::EXITED => "exit {$this->mainStatus}" . (isset(ExitCode::TABLE[$this->mainStatus]) ? ' (' . ExitCode::TABLE[$this->mainStatus][0] . ')' : ''),
            self::KILLED, self::DUMPED => ($this->mainCode === self::DUMPED ? 'core dumped, ' : '') . "killed by signal {$this->mainStatus}" . (isset(self::SIGNALS[$this->mainStatus]) ? ' (' . self::SIGNALS[$this->mainStatus] . ')' : ''),
            default => 'not started',
        };

        return in_array($this->result, ['', 'exit-code', 'signal', 'core-dump'], true) ? $how : "{$how}, result {$this->result}";
    }
}
