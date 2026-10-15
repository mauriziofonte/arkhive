<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Resilience;

use Closure;
use DateTimeImmutable;
use Mfonte\Arkhive\Application\Resilience\StaleLocks;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\RepositoryLock;
use Mfonte\Arkhive\Domain\System\ProcessState;
use Mfonte\Arkhive\Exceptions\LockedException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeProcessTable;
use Tests\Support\FixedClock;

final class StaleLocksTest extends TestCase
{
    private const HOST = 'host.example.com';

    private const STDERR = "unable to create lock in backend: repository is already locked by PID 25983 on host.example.com by root (UID 0, GID 0)\nlock was created at 2026-10-12 10:18:02 (2m28s ago)\nstorage ID 6d287ce8\nthe `unlock` command can be used to remove stale locks";

    private const MESSAGE = 'The restic repository is locked by PID 25983 on host.example.com by root (UID 0, GID 0), created at 2026-10-12 10:18:02 (2m28s ago)';

    private const COMMAND = "RESTIC_PASSWORD_FILE='/etc/arkhive/restic.pass' restic -r 'sftp:u123@backup.example.com:/restic' unlock";

    private FakeProcessTable $processes;
    private bool $arkhiveRunning = false;

    /** @var list<RepositoryLock> */
    private array $locks = [];

    private int $listed = 0;

    protected function setUp(): void
    {
        $this->processes = new FakeProcessTable();
    }

    private function examine(bool $unlockStale = false, string $stderr = self::STDERR): ?LockedException
    {
        $staleLocks = new StaleLocks($this->processes, new FixedClock(), self::HOST, function (string $repository): bool {
            self::assertSame('sftp:u123@backup.example.com:/restic', $repository);

            return $this->arkhiveRunning;
        });
        $settings = new ResticSettings('sftp:u123@backup.example.com:/restic', '/etc/arkhive/restic.pass', unlockStale: $unlockStale);

        return $staleLocks->examine(new LockedException(self::MESSAGE, 'If no other restic process...'), $stderr, $settings, $this->listLocks(...), static function (): void {
        });
    }

    /**
     * @return list<RepositoryLock>
     */
    private function listLocks(): array
    {
        $this->listed++;

        return $this->locks;
    }

    private static function lock(int $pid, string $hostname = self::HOST, string $time = '2026-10-12T10:18:02+02:00'): RepositoryLock
    {
        return new RepositoryLock(str_repeat('a', 64), new DateTimeImmutable($time), false, $hostname, 'root', $pid);
    }

    public function testADeadProcessOnThisHostIsAStaleLockNotWaitedFor(): void
    {
        $stale = $this->examine();

        self::assertNotNull($stale);
        self::assertSame(self::MESSAGE . ': a stale lock from a dead process on this host (PID 25983 no longer exists)', $stale->getMessage());
        self::assertSame('Nothing will release it. Remove it with: ' . self::COMMAND . ' (restic removes only the locks it finds stale), or set UNLOCK_STALE=yes to let ArkHive do so; ArkHive never removes a lock unless told to', $stale->hint());
        self::assertSame(ExitCode::TEMPORARY, $stale->exitCode());
        self::assertFalse($stale->transient(), 'nothing will release it: waiting LOCK_WAIT is pointless');
        self::assertNull($stale->unlock(), 'never removed unless UNLOCK_STALE is set');
        self::assertSame(0, $this->listed);
    }

    public function testAZombieIsDeadButResticUnlockWouldKeepItsLock(): void
    {
        $this->processes->states[25983] = ProcessState::Zombie;
        $this->processes->parents[25983] = 4100;

        $stale = $this->examine(unlockStale: true);

        self::assertNotNull($stale);
        self::assertSame(self::MESSAGE . ': a stale lock from a dead process on this host (PID 25983 is a zombie: it has exited, but its parent PID 4100 has not collected it)', $stale->getMessage());
        self::assertSame("restic unlock keeps a zombie's lock: stop or restart its parent so that it collects the zombie, then run: " . self::COMMAND, $stale->hint());
        self::assertNull($stale->unlock(), 'restic unlock would not remove it');
        self::assertFalse($stale->transient());
    }

    public function testAHolderThatMayBeAliveIsLeftToTheUsualWait(): void
    {
        $this->processes->states[25983] = ProcessState::Running;
        self::assertNull($this->examine(unlockStale: true), 'the process is running');

        $this->processes->states = [];
        $this->arkhiveRunning = true;
        self::assertNull($this->examine(unlockStale: true), 'another ArkHive run uses the repository');

        $this->arkhiveRunning = false;
        self::assertNull($this->examine(stderr: str_replace('on host.example.com', 'on web2.example.com', self::STDERR)), 'made on another host: its PID means nothing here');
        self::assertNull($this->examine(stderr: 'Fatal: unable to create lock in backend: repository is already locked'), 'no lock named');
    }

    public function testUnlockStaleGivesTheUnlockWhenNothingAliveWouldBeSignalled(): void
    {
        $this->locks = [
            self::lock(25983),
            self::lock(31000, 'web2.example.com'),
            self::lock(31001, time: '2026-10-12T09:40:00+02:00'),
            self::lock(31002),
        ];
        $this->processes->states = [31000 => ProcessState::Running, 31001 => ProcessState::Running, 31002 => ProcessState::Zombie];

        $stale = $this->examine(unlockStale: true);

        self::assertNotNull($stale);
        self::assertSame(self::MESSAGE . ': a stale lock from a dead process on this host (PID 25983 no longer exists)', $stale->getMessage());
        self::assertSame('Remove it with: ' . self::COMMAND, $stale->hint());
        self::assertInstanceOf(Closure::class, $stale->unlock(), 'another host, a lock older than 30 minutes and a zombie get no signal that matters');
        self::assertSame(1, $this->listed);
    }

    public function testUnlockStaleLeavesTheLockWhenResticUnlockWouldSignalALiveProcess(): void
    {
        $this->locks = [self::lock(25983), self::lock(31003, time: '2026-10-12T09:51:00+02:00')];
        $this->processes->states = [31003 => ProcessState::Running];

        $stale = $this->examine(unlockStale: true);

        self::assertNotNull($stale);
        self::assertNull($stale->unlock());
        self::assertSame('UNLOCK_STALE is set, but ArkHive leaves the lock: PID 31003 also holds a lock on this host and is running, and restic unlock would send it SIGHUP. Once that is sorted out, remove it with: ' . self::COMMAND, $stale->hint());
    }

    public function testUnlockStaleLeavesTheLockWhenTheLocksCannotBeListed(): void
    {
        $staleLocks = new StaleLocks($this->processes, new FixedClock(), self::HOST, static fn (): bool => false);
        $settings = new ResticSettings('sftp:u123@backup.example.com:/restic', '/etc/arkhive/restic.pass', unlockStale: true);

        $stale = $staleLocks->examine(new LockedException(self::MESSAGE), self::STDERR, $settings, static fn (): array => throw UnavailableException::network('Listing the repository locks failed (restic exit 1): connection lost'), static function (): void {
        });

        self::assertNotNull($stale);
        self::assertNull($stale->unlock());
        self::assertStringStartsWith('UNLOCK_STALE is set, but ArkHive leaves the lock: the repository locks could not be listed (Listing the repository locks failed (restic exit 1): connection lost)', (string) $stale->hint());
    }
}
