<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Process;

use InvalidArgumentException;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    public function testDisplayQuotesOnlyWhatNeedsQuoting(): void
    {
        $command = new Command(['/usr/bin/restic', 'backup', '--tag', 'arkhive', '/srv/my site', "it's", '', '--exclude-file=/tmp/x', 'a*b']);

        self::assertSame("/usr/bin/restic backup --tag arkhive '/srv/my site' 'it'\\''s' '' --exclude-file=/tmp/x 'a*b'", $command->display());
        self::assertSame('restic', $command->name());
    }

    public function testWithersKeepTheOtherFields(): void
    {
        $command = new Command(['/bin/true'], ['A' => '1'], '/tmp', null, 5.0);
        $changed = $command->withEnv(['B' => '2', 'A' => null])->withInput('data')->withTimeout(9.5);

        self::assertSame(['A' => null, 'B' => '2'], $changed->env);
        self::assertSame('/tmp', $changed->cwd);
        self::assertSame('data', $changed->input);
        self::assertSame(9.5, $changed->timeout);
        self::assertSame(5.0, $command->timeout);
        self::assertSame(['A' => '1'], $command->env);
    }

    public function testTheStallTimeoutAndItsProgressFileSurviveTheOtherWithers(): void
    {
        $command = (new Command(['/usr/bin/mysqldump']))->withStallTimeout(300.0, '/var/backups/dumps/mysql/shop.sql.partial');
        $changed = $command->withEnv(['A' => '1'])->withInput('x')->withInputFile('/etc/hostname')->withTimeout(60.0)->withOutput(STDOUT);

        self::assertSame(300.0, $changed->stallTimeout);
        self::assertSame('/var/backups/dumps/mysql/shop.sql.partial', $changed->progressFile);
        self::assertSame(60.0, $changed->timeout);
        self::assertNull((new Command(['/bin/true']))->stallTimeout);
        self::assertNull((new Command(['/bin/true']))->withStallTimeout(5.0)->progressFile, 'output alone is the progress');
    }

    public function testATimeoutMustBePositive(): void
    {
        foreach ([[0.0, null], [-1.0, null], [null, 0.0], [null, -5.0]] as [$timeout, $stall]) {
            try {
                new Command(['/bin/true'], timeout: $timeout, stallTimeout: $stall);
                self::fail('accepted a timeout of ' . var_export([$timeout, $stall], true));
            } catch (InvalidArgumentException $e) {
                self::assertSame('A command timeout must be positive', $e->getMessage());
            }
        }
    }

    public function testEmptyArgvIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Command([]);
    }

    public function testEmptyBinaryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Command(['']);
    }

    public function testFailureMessagesCarryTheStderrTail(): void
    {
        $command = new Command(['/usr/bin/restic', 'check']);
        $result = new ProcessResult($command, 1, '', "line 1\n\nline 2\r\n  line 3  \nFatal: repository is damaged\n\n");

        self::assertFalse($result->succeeded());
        self::assertSame('line 2 | line 3 | Fatal: repository is damaged', $result->stderrTail());
        self::assertSame('Fatal: repository is damaged', $result->stderrTail(1));
        self::assertSame('Repository check failed (restic exit 1): line 2 | line 3 | Fatal: repository is damaged', $result->failure('Repository check'));
        self::assertSame('Dump failed (restic exit 2)', (new ProcessResult($command, 2, '', "\n"))->failure('Dump'));
        self::assertTrue((new ProcessResult($command, 0, 'ok', ''))->succeeded());
    }

    public function testJsonRecordsAndRepeatedLinesAreReadOnce(): void
    {
        $command = new Command(['/usr/bin/restic', 'backup']);
        $stderr = str_repeat('{"message_type":"error","error":{"message":"read /srv/www/a: input/output error"},"during":"archival","item":"/srv/www/a"}' . "\n", 4)
            . "\e[2Ksignal terminated received, cleaning up\n"
            . '{"message_type":"exit_error","code":11,"message":"repository is already locked by PID 1 on host.example.com\nlock was created at 2026-10-12 13:58:02"}' . "\n"
            . "{not json}\n";

        $result = new ProcessResult($command, 1, '', $stderr);

        self::assertSame('repository is already locked by PID 1 on host.example.com | lock was created at 2026-10-12 13:58:02 | {not json}', $result->stderrTail());
        self::assertSame('read /srv/www/a: input/output error | signal terminated received, cleaning up | repository is already locked by PID 1 on host.example.com | lock was created at 2026-10-12 13:58:02 | {not json}', $result->stderrTail(10), 'escape sequences removed, each line once');
        self::assertSame('b | a | c', (new ProcessResult($command, 1, '', "a\nb\na\nc\n"))->stderrTail(), 'a line repeated is kept where it last appears');
    }

    public function testASignalFromOutsideIsNamed(): void
    {
        $command = new Command(['/usr/bin/mariadb-dump']);

        self::assertSame('Dump failed (mariadb-dump killed by SIGKILL)', (new ProcessResult($command, 137, '', '', 1.0, false, 9))->failure('Dump'));
        self::assertSame('Dump failed (mariadb-dump killed by signal 31)', (new ProcessResult($command, 159, '', '', 1.0, false, 31))->failure('Dump'));
        self::assertSame('SIGTERM', ProcessResult::signalName(15));
    }

    public function testTimeoutMessage(): void
    {
        $result = new ProcessResult((new Command(['/usr/bin/restic', 'cat', 'config']))->withTimeout(30.0), 143, '', '', 30.1, true);

        self::assertSame('Repository access timed out after 30s (restic)', $result->failure('Repository access'));
    }

    public function testStreamOptionsSurviveEveryWither(): void
    {
        $sink = fopen('php://memory', 'w+b');
        self::assertIsResource($sink);
        $command = (new Command(['/usr/bin/mariadb', 'shop']))->withInputFile('/tmp/shop.sql')->withOutput($sink)->withTimeout(5.0)->withEnv(['A' => 'b']);

        self::assertSame('/tmp/shop.sql', $command->inputFile);
        self::assertSame($sink, $command->output);
        self::assertSame(5.0, $command->timeout);
        self::assertNull($command->input);

        $withInput = $command->withInput('SELECT 1');
        self::assertSame('SELECT 1', $withInput->input);
        self::assertNull($withInput->inputFile, 'string input replaces the input file');
        self::assertSame($sink, $withInput->output);
        self::assertSame('/tmp/x.sql', $withInput->withInputFile('/tmp/x.sql')->inputFile);
        self::assertNull($withInput->withInputFile('/tmp/x.sql')->input);
    }

    public function testInputFromBothAStringAndAFileIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Command(['/bin/cat'], input: 'x', inputFile: '/tmp/x');
    }

    public function testOutputMustBeAStream(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Command(['/bin/cat'], output: '/tmp/out');
    }
}
