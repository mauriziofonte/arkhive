<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Process;

/**
 * Outcome of a finished process. stderr is always kept (the tail, when it is very long).
 */
final readonly class ProcessResult
{
    /** signal numbers of Linux, for messages */
    public const SIGNALS = [1 => 'SIGHUP', 2 => 'SIGINT', 3 => 'SIGQUIT', 6 => 'SIGABRT', 9 => 'SIGKILL', 11 => 'SIGSEGV', 13 => 'SIGPIPE', 15 => 'SIGTERM'];

    /**
     * @param int|null $signal the signal that killed the process when ArkHive did not send it (its
     *                         exit code is then 128 + signal); null when it exited by itself
     * @param bool     $stalled  stopped for making no progress (Command::$stallTimeout); $timedOut is then true too
     */
    public function __construct(
        public Command $command,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $duration = 0.0,
        public bool $timedOut = false,
        public ?int $signal = null,
        public bool $stalled = false,
    ) {
    }

    public static function signalName(int $signal): string
    {
        return self::SIGNALS[$signal] ?? "signal {$signal}";
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * "<what> failed (restic exit 1): <last stderr lines>", for error messages.
     */
    public function failure(string $what): string
    {
        $message = match (true) {
            $this->stalled => sprintf('%s made no progress for %ss and was stopped (%s)', $what, (string) $this->command->stallTimeout, $this->command->name()),
            $this->timedOut => sprintf('%s timed out after %ss (%s)', $what, (string) $this->command->timeout, $this->command->name()),
            $this->signal !== null => sprintf('%s failed (%s killed by %s)', $what, $this->command->name(), self::signalName($this->signal)),
            default => sprintf('%s failed (%s exit %d)', $what, $this->command->name(), $this->exitCode),
        };
        $detail = $this->stderrTail();

        return $detail === '' ? $message : $message . ': ' . $detail;
    }

    /**
     * The last stderr lines joined with " | ", at most $lines of them: a JSON record (restic --json)
     * is replaced by its message, terminal escape sequences are removed, and a line repeated is kept
     * once, where it last appears.
     */
    public function stderrTail(int $lines = 3): string
    {
        $all = [];
        foreach (preg_split('/\R/', $this->stderr) ?: [] as $line) {
            $line = trim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line));
            foreach (preg_split('/\R/', self::message($line)) ?: [] as $part) {
                if (trim($part) !== '') {
                    $all[] = trim($part);
                }
            }
        }
        $unique = array_values(array_reverse(array_unique(array_reverse($all))));

        return implode(' | ', array_slice($unique, -$lines));
    }

    /**
     * The message of a JSON error record ({"message": ...} or {"error": {"message": ...}}), or the line.
     */
    private static function message(string $line): string
    {
        if (!str_starts_with($line, '{')) {
            return $line;
        }
        $data = json_decode($line, true);
        if (!is_array($data)) {
            return $line;
        }
        $error = $data['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return is_string($data['message'] ?? null) ? $data['message'] : $line;
    }
}
