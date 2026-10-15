<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\System;

use Mfonte\Arkhive\Application\Port\ProcessTable;
use Mfonte\Arkhive\Domain\System\ProcessState;

/**
 * kill(pid, 0) says whether a PID exists (EPERM: it does, owned by another user); /proc/<pid>/stat
 * says whether it is a zombie and who its parent is.
 */
final class ProcProcessTable implements ProcessTable
{
    private const EPERM = 1;

    public function __construct(private readonly string $proc = '/proc')
    {
    }

    public function state(int $pid): ProcessState
    {
        if ($pid <= 0) {
            return ProcessState::Running;
        }
        if (!posix_kill($pid, 0) && posix_get_last_error() !== self::EPERM) {
            return ProcessState::Gone;
        }

        return ($this->stat($pid)[0] ?? null) === 'Z' ? ProcessState::Zombie : ProcessState::Running;
    }

    public function parent(int $pid): ?int
    {
        return $pid > 0 ? $this->stat($pid)[1] ?? null : null;
    }

    /**
     * The state and the parent PID from /proc/<pid>/stat: "pid (comm) state ppid ...", where comm
     * may itself hold spaces and parentheses, so the fields are read after the last ")".
     *
     * @return array{0?: string, 1?: int}
     */
    private function stat(int $pid): array
    {
        $stat = @file_get_contents("{$this->proc}/{$pid}/stat");
        $end = is_string($stat) ? strrpos($stat, ')') : false;
        if (!is_string($stat) || $end === false) {
            return [];
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: [];
        if (count($fields) < 2 || preg_match('/^[A-Za-z]$/', $fields[0]) !== 1 || !ctype_digit($fields[1])) {
            return [];
        }

        return [$fields[0], (int) $fields[1]];
    }
}
