<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Resilience;

use Closure;
use Mfonte\Arkhive\Application\Port\ProcessTable;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\LockConflict;
use Mfonte\Arkhive\Domain\Repository\RepositoryLock;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Domain\System\ProcessState;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\LockedException;

/**
 * A restic lock left by a process that died on this host (kill -9, the out-of-memory killer, a
 * crash) is never released: restic does not skip stale locks, it only removes them on `restic
 * unlock`. Such a lock is reported as what it is and not waited for; it is removed only when
 * UNLOCK_STALE allows it, and only when `restic unlock` cannot touch anything alive.
 */
final class StaleLocks
{
    /**
     * @param Closure(string): bool $arkhiveRunning whether another ArkHive run on this host holds the
     *                                              lock of the repository (its restic may have made the lock)
     */
    public function __construct(
        private readonly ProcessTable $processes,
        private readonly Clock $clock,
        private readonly string $hostname,
        private readonly Closure $arkhiveRunning,
    ) {
    }

    /**
     * The lock restic refused to run on, when its process is dead on this host and no other ArkHive
     * run uses the repository; null when its holder may still be alive.
     *
     * @param string                          $stderr restic's error text (JSON records decoded)
     * @param Closure(): list<RepositoryLock> $locks  every lock in the repository, read only before unlocking
     * @param Closure(): void                 $unlock restic unlock
     */
    public function examine(LockedException $locked, string $stderr, ResticSettings $settings, Closure $locks, Closure $unlock): ?LockedException
    {
        $conflict = LockConflict::parse($stderr);
        if ($conflict === null || !$conflict->madeOn($this->hostname)) {
            return null;
        }
        $state = $this->processes->state($conflict->pid);
        if ($state === ProcessState::Running || ($this->arkhiveRunning)($settings->repository)) {
            return null;
        }

        $command = $settings->commandLine() . ' unlock';
        if ($state === ProcessState::Zombie) {
            $parent = $this->processes->parent($conflict->pid);

            return LockedException::stale(
                $locked->getMessage() . ": a stale lock from a dead process on this host (PID {$conflict->pid} is a zombie: it has exited, but its parent" . ($parent === null ? '' : " PID {$parent}") . ' has not collected it)',
                'restic unlock keeps a zombie\'s lock: stop or restart its parent so that it collects the zombie, then run: ' . $command,
            );
        }
        $message = $locked->getMessage() . ": a stale lock from a dead process on this host (PID {$conflict->pid} no longer exists)";
        if (!$settings->unlockStale) {
            return LockedException::stale($message, self::blocks($conflict) . " Remove it with: {$command} (restic removes only the locks it finds stale), or set UNLOCK_STALE=yes to let ArkHive do so; ArkHive never removes a lock unless told to");
        }
        $blocker = $this->blocker($locks);
        if ($blocker !== null) {
            return LockedException::stale($message, "UNLOCK_STALE is set, but ArkHive leaves the lock: {$blocker}. Once that is sorted out, remove it with: {$command}");
        }

        return LockedException::stale($message, "Remove it with: {$command}", $unlock);
    }

    /**
     * What a lock left behind does: a run that ended without removing it (killed, or the repository
     * stopped answering, as when the server froze) leaves it for good.
     */
    private static function blocks(LockConflict $conflict): string
    {
        $effect = $conflict->exclusive
            ? 'no backup, restore or maintain can run until it is removed.'
            : 'backups still run alongside it, but every maintain (forget, prune, check) stops on it until it is removed.';

        return 'Its run ended without removing it (killed, or the repository stopped answering), and nothing will release it: ' . $effect;
    }

    /**
     * Why `restic unlock` must not run now, or null. It sends SIGHUP to the PID of every lock made on
     * this host in the last 30 minutes to see whether it is alive: restic ignores it and a zombie
     * cannot receive it, but another program that took over the PID would.
     *
     * @param Closure(): list<RepositoryLock> $locks
     */
    private function blocker(Closure $locks): ?string
    {
        try {
            $all = $locks();
        } catch (ArkhiveException $e) {
            return 'the repository locks could not be listed (' . $e->getMessage() . ')';
        }
        $now = $this->clock->now()->getTimestamp();
        foreach ($all as $lock) {
            $recent = $now - $lock->time->getTimestamp() <= RepositoryLock::STALE_AFTER;
            if ($lock->hostname === $this->hostname && $recent && $this->processes->state($lock->pid) === ProcessState::Running) {
                return "PID {$lock->pid} also holds a lock on this host and is running, and restic unlock would send it SIGHUP";
            }
        }

        return null;
    }
}
