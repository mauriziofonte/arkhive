<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\System;

use Mfonte\Arkhive\Domain\System\ProcessState;
use Mfonte\Arkhive\Infrastructure\System\ProcProcessTable;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class ProcProcessTableTest extends TestCase
{
    use TempDir;

    protected function setUp(): void
    {
        if (!is_dir('/proc/self')) {
            self::markTestSkipped('needs /proc');
        }
    }

    public function testARunningProcessAndItsParent(): void
    {
        $table = new ProcProcessTable();

        self::assertSame(ProcessState::Running, $table->state((int) getmypid()));
        self::assertSame(posix_getppid(), $table->parent((int) getmypid()));
    }

    public function testAPidNoProcessHasIsGone(): void
    {
        $max = (int) trim((string) @file_get_contents('/proc/sys/kernel/pid_max')) ?: 4194304;
        $table = new ProcProcessTable();

        self::assertSame(ProcessState::Gone, $table->state($max + 1));
        self::assertNull($table->parent($max + 1));
    }

    public function testAProcessOfAnotherUserIsRunning(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root may signal every process');
        }

        self::assertSame(ProcessState::Running, (new ProcProcessTable())->state(1), 'EPERM: PID 1 exists');
    }

    public function testAnUnknownPidIsNeverCalledDead(): void
    {
        $table = new ProcProcessTable();

        self::assertSame(ProcessState::Running, $table->state(0));
        self::assertSame(ProcessState::Running, $table->state(-1));
        self::assertNull($table->parent(0));
    }

    public function testAnExitedChildNotYetCollectedIsAZombie(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'echo getmypid(), "\n";'], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $pid = (int) fgets($pipes[1]);
        try {
            // proc_get_status would collect it: wait for the zombie by reading /proc only
            $stat = '';
            for ($i = 0; $i < 500 && !str_contains($stat, ') Z '); $i++) {
                usleep(10000);
                $stat = (string) @file_get_contents("/proc/{$pid}/stat");
            }
            self::assertStringContainsString(') Z ', $stat);

            $table = new ProcProcessTable();
            self::assertSame(ProcessState::Zombie, $table->state($pid));
            self::assertSame(getmypid(), $table->parent($pid));
        } finally {
            fclose($pipes[1]);
            proc_close($process);
        }
        self::assertSame(ProcessState::Gone, (new ProcProcessTable())->state($pid), 'collected');
    }

    public function testTheCommandNameMayHoldSpacesAndParentheses(): void
    {
        $proc = $this->tempDir();
        $pid = (int) getmypid();
        mkdir("{$proc}/{$pid}");
        file_put_contents("{$proc}/{$pid}/stat", "{$pid} (rest) Z (x) Z 4321 {$pid} {$pid} 0 -1 4194560 0 0 0 0\n");

        $table = new ProcProcessTable($proc);
        self::assertSame(ProcessState::Zombie, $table->state($pid));
        self::assertSame(4321, $table->parent($pid));

        file_put_contents("{$proc}/{$pid}/stat", "garbage\n");
        self::assertSame(ProcessState::Running, $table->state($pid), 'an unreadable stat leaves a live PID running');
        self::assertNull($table->parent($pid));
    }
}
