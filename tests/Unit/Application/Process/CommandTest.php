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
