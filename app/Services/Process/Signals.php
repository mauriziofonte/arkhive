<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Process;

use Mfonte\Arkhive\Exceptions\InterruptedException;

/**
 * SIGINT/SIGTERM handling. A signal is recorded, forwarded to the running child processes through
 * the listeners, and turned into an InterruptedException at the next check point, so that cleanup
 * (temp files, dump staging, lock) always runs before ArkHive exits 130 or 143.
 *
 * restic handles the first SIGINT/SIGTERM by cancelling cleanly and ignores further ones, so a child
 * that also received the signal from the terminal's process group is not harmed by the forwarded copy.
 */
final class Signals
{
    private ?int $received = null;
    private bool $installed = false;

    /** @var array<int, callable(int): void> */
    private array $listeners = [];
    private int $nextListener = 0;

    public static function supported(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    }

    /**
     * Installs the handlers (no-op without ext-pcntl). Returns whether signals are now handled.
     */
    public function install(): bool
    {
        if ($this->installed) {
            return true;
        }
        if (!self::supported()) {
            return false;
        }
        pcntl_async_signals(true);
        foreach ([InterruptedException::SIGINT, InterruptedException::SIGTERM] as $signal) {
            pcntl_signal($signal, function (int $signal): void {
                $this->receive($signal);
            });
        }

        return $this->installed = true;
    }

    public function uninstall(): void
    {
        if (!$this->installed) {
            return;
        }
        foreach ([InterruptedException::SIGINT, InterruptedException::SIGTERM] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        $this->installed = false;
    }

    public function installed(): bool
    {
        return $this->installed;
    }

    /**
     * Called by the signal handler (and by tests). The first signal is the one reported.
     */
    public function receive(int $signal): void
    {
        $this->received ??= $signal;
        foreach ($this->listeners as $listener) {
            $listener($signal);
        }
    }

    public function received(): ?int
    {
        return $this->received;
    }

    /**
     * Check point: throws if SIGINT or SIGTERM arrived.
     *
     * @throws InterruptedException
     */
    public function check(): void
    {
        if ($this->received !== null) {
            throw new InterruptedException($this->received);
        }
    }

    /**
     * @param callable(int): void $listener
     */
    public function listen(callable $listener): int
    {
        $id = $this->nextListener++;
        $this->listeners[$id] = $listener;

        return $id;
    }

    public function forget(int $id): void
    {
        unset($this->listeners[$id]);
    }
}
