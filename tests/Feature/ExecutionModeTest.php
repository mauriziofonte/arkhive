<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\Cli;
use Tests\Support\TempDir;

/**
 * The real entry point under a pseudo-TTY and without a TTY (spec §11, §13.4).
 */
#[Group('process')]
final class ExecutionModeTest extends TestCase
{
    use TempDir;

    public function testWithoutATerminalOutputIsTimestampedPlainLinesWithoutColour(): void
    {
        $result = Cli::run(['internal:context', '--sample-log']);

        self::assertSame(0, $result->exitCode);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d warning sample warning$/m', $result->stderr);
        self::assertStringNotContainsString("\033[", $result->output());
        self::assertStringNotContainsString('⚠️', $result->output());
        self::assertSame('{"interactive":false,"systemd":false,"colour":false,"log_format":"plain","reason":"no terminal","log_target":"stderr","log_target_reason":"default"}', trim($result->stdout));
    }

    public function testUnderAPseudoTerminalOutputIsHumanWithColour(): void
    {
        $result = Cli::run(['internal:context', '--sample-log'], pty: true);

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString("\033[33m⚠️  sample warning\033[0m", $result->stdout);
        self::assertStringContainsString('"interactive":true', $result->stdout);
        self::assertStringContainsString('"reason":"terminal"', $result->stdout);
    }

    public function testNoColourUnderAPseudoTerminal(): void
    {
        $result = Cli::run(['internal:context', '--sample-log'], ['NO_COLOR' => '1'], pty: true);

        self::assertStringContainsString('⚠️  sample warning', $result->stdout);
        self::assertStringNotContainsString("\033[33m", $result->stdout);
        self::assertStringContainsString('"colour":false', $result->stdout);
    }

    public function testNoInteractionUnderAPseudoTerminal(): void
    {
        $result = Cli::run(['internal:context', '-n'], pty: true);

        self::assertStringContainsString('"interactive":false', $result->stdout);
        self::assertStringContainsString('"reason":"--no-interaction"', $result->stdout);
    }

    public function testSystemdGetsJournaldPriorities(): void
    {
        $result = Cli::run(['internal:context', '--sample-log'], ['INVOCATION_ID' => '0123456789abcdef']);

        self::assertStringContainsString("<4>sample warning\n<3>sample error\n", $result->stderr);
        self::assertStringContainsString('"systemd":true', $result->stdout);
    }

    public function testCiIsUnattendedEvenUnderAPseudoTerminal(): void
    {
        $result = Cli::run(['internal:context'], ['CI' => 'true'], pty: true);

        self::assertStringContainsString('"reason":"CI"', $result->stdout);
    }

    public function testJsonLogFormat(): void
    {
        $result = Cli::run(['internal:context', '--sample-log', '--log-format=json']);

        $first = json_decode(strtok($result->stderr, "\n") ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertSame(['time', 'level', 'message'], array_keys($first));
        self::assertSame('sample info', $first['message']);
    }

    public function testInteractiveWithoutATerminalIsExit2(): void
    {
        $result = Cli::run(['internal:context', '--interactive']);

        self::assertSame(ExitCode::USAGE, $result->exitCode);
        self::assertStringContainsString('error --interactive needs a terminal on stdin', $result->stderr);
    }

    /**
     * The real stderr's device and inode against $JOURNAL_STREAM: the journal is chosen only while
     * stderr is still the stream systemd connected.
     */
    public function testTheJournalIsChosenOnlyWhenStderrIsTheJournalStream(): void
    {
        $dir = $this->tempDir();
        $stderr = "{$dir}/stderr";
        touch($stderr);
        $stat = stat($stderr);
        self::assertIsArray($stat);

        foreach (["{$stat['dev']}:{$stat['ino']}" => 'journal', "{$stat['dev']}:1" => 'stderr'] as $stream => $target) {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/arkhive', 'internal:context'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'a']],
                $pipes,
                null,
                ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'INVOCATION_ID' => '0123456789abcdef', 'JOURNAL_STREAM' => $stream],
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            self::assertSame(0, proc_close($process), (string) file_get_contents($stderr));
            self::assertStringContainsString("\"log_target\":\"{$target}\"", $stdout);
        }
    }

    public function testAStderrReaderThatWentAwayDoesNotFailTheRun(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/password", "secret\n");
        chmod("{$dir}/password", 0600);
        file_put_contents("{$dir}/config", "BACKUP_PATHS={$dir}\nRESTIC_REPOSITORY=/srv/restic-repo\nRESTIC_PASSWORD_FILE={$dir}/password\n");
        chmod("{$dir}/config", 0600);

        // `arkhive ... 2>&1 | grep -q`, or journald restarted: stderr is a pipe nobody reads any more
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/arkhive', 'schedule', '--systemd', '--config', "{$dir}/config"],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        fclose($pipes[2]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        self::assertSame(ExitCode::SUCCESS, proc_close($process), 'the warning about notifications cannot be written, the units still are');
        self::assertStringContainsString('[Service]', $stdout);
    }
}
