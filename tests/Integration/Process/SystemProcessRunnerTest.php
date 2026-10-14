<?php

declare(strict_types=1);

namespace Tests\Integration\Process;

use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\InterruptedException;
use Mfonte\Arkhive\Infrastructure\Process\Signals;
use Mfonte\Arkhive\Infrastructure\Process\SystemProcessRunner;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * The runner against real child processes (PHP itself, so no shell tool is assumed).
 */
final class SystemProcessRunnerTest extends TestCase
{
    use TempDir;

    private Signals $signals;
    private SystemProcessRunner $runner;

    protected function setUp(): void
    {
        $this->signals = new Signals();
        $this->runner = new SystemProcessRunner($this->signals, ['PATH' => '/usr/bin:/bin', 'LANG' => 'it_IT.UTF-8', 'KEEP' => 'kept', 'DROP' => 'x']);
    }

    private static function php(string $code): Command
    {
        return new Command([PHP_BINARY, '-n', '-r', $code]);
    }

    public function testExitCodeStdoutAndStderrAreKept(): void
    {
        $result = $this->runner->run(self::php('echo "out"; fwrite(STDERR, "warning: something\n"); exit(3);'));

        self::assertSame(3, $result->exitCode);
        self::assertSame('out', $result->stdout);
        self::assertSame("warning: something\n", $result->stderr);
        self::assertFalse($result->timedOut);
        self::assertGreaterThan(0.0, $result->duration);
    }

    public function testEnvironmentHasLcAllCAndCommandOverrides(): void
    {
        $command = self::php('echo json_encode(getenv());')->withEnv(['DROP' => null, 'ADDED' => 'secret-file-path', 'LC_ALL' => 'C']);
        $env = json_decode($this->runner->run($command)->stdout, true);

        self::assertIsArray($env);
        self::assertSame('C', $env['LC_ALL']);
        self::assertSame('kept', $env['KEEP']);
        self::assertSame('secret-file-path', $env['ADDED']);
        self::assertArrayNotHasKey('DROP', $env);
    }

    public function testInheritsTheRealEnvironmentByDefault(): void
    {
        putenv('ARKHIVE_RUNNER_PROBE=1');
        try {
            $env = json_decode((new SystemProcessRunner(new Signals()))->run(self::php('echo json_encode(getenv());'))->stdout, true);
        } finally {
            putenv('ARKHIVE_RUNNER_PROBE');
        }
        self::assertIsArray($env);
        self::assertSame('1', $env['ARKHIVE_RUNNER_PROBE']);
        self::assertSame('C', $env['LC_ALL']);
    }

    public function testStdinIsDevNullUnlessInputIsGiven(): void
    {
        self::assertSame('0', $this->runner->run(self::php('echo strlen(stream_get_contents(STDIN));'))->stdout);
        self::assertSame('hello', $this->runner->run(self::php('echo stream_get_contents(STDIN);')->withInput('hello'))->stdout);
    }

    public function testStdinCanComeFromAFile(): void
    {
        $file = $this->tempDir() . '/input.sql';
        file_put_contents($file, str_repeat("INSERT INTO t VALUES (1);\n", 50000));

        $result = $this->runner->run(self::php('echo strlen(stream_get_contents(STDIN));')->withInputFile($file));

        self::assertSame((string) filesize($file), $result->stdout);
    }

    public function testAMissingInputFileIsAClearErrorAndNothingRuns(): void
    {
        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('its input /nonexistent/dump.sql is not a readable file');

        $this->runner->run(self::php('echo 1;')->withInputFile('/nonexistent/dump.sql'));
    }

    public function testStdoutCanBeStreamedToAStreamWithoutBeingKept(): void
    {
        $sink = fopen('php://temp', 'w+b');
        self::assertIsResource($sink);
        $bytes = random_bytes(300000);
        $source = $this->tempDir() . '/blob';
        file_put_contents($source, $bytes);

        $result = $this->runner->run(self::php('readfile(' . var_export($source, true) . '); fwrite(STDERR, "done\n");')->withOutput($sink));

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame("done\n", $result->stderr);
        rewind($sink);
        self::assertSame(hash('sha256', $bytes), hash('sha256', (string) stream_get_contents($sink)));
    }

    public function testAClosedOutputStreamStopsTheProducer(): void
    {
        $sink = fopen('php://memory', 'rb');
        self::assertIsResource($sink);

        $result = $this->runner->run(self::php('while (true) { echo str_repeat("x", 65536); }')->withOutput($sink)->withTimeout(20));

        self::assertFalse($result->timedOut, 'the producer was stopped, not left running until the timeout');
        self::assertNotSame(0, $result->exitCode);
        self::assertStringContainsString('the output stream was closed before ' . basename(PHP_BINARY) . ' finished', $result->stderr);
    }

    public function testAFullOutputFilesystemIsNamedAsSuch(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('needs /dev/full');
        }
        $sink = fopen('/dev/full', 'wb');
        self::assertIsResource($sink);

        $result = $this->runner->run(self::php('while (true) { echo str_repeat("x", 65536); }')->withOutput($sink)->withTimeout(20));
        fclose($sink);

        self::assertFalse($result->timedOut);
        self::assertStringContainsString('the output stream was closed before ' . basename(PHP_BINARY) . ' finished (No space left on device)', $result->stderr);
    }

    public function testLargeInputAndOutputDoNotDeadlock(): void
    {
        $input = str_repeat("0123456789abcdef\n", 200000);
        $result = $this->runner->run(self::php('while (($l = fgets(STDIN)) !== false) { echo $l; fwrite(STDERR, $l); }')->withInput($input));

        self::assertSame(0, $result->exitCode);
        self::assertSame(strlen($input), strlen($result->stdout));
        self::assertSame($input, $result->stdout);
        self::assertSame(SystemProcessRunner::STDERR_LIMIT, strlen($result->stderr), 'stderr keeps its tail only');
        self::assertStringEndsWith("0123456789abcdef\n", $result->stderr);
    }

    public function testStdoutAndStderrAreStreamedLineByLine(): void
    {
        $out = [];
        $err = [];
        $result = $this->runner->run(
            self::php('echo "{\"a\":1}\r\n"; fflush(STDOUT); usleep(50000); echo "{\"b\":2}\n{\"c\""; echo ":3}"; fwrite(STDERR, "e1\ne2");'),
            static function (string $line) use (&$out): void {
                $out[] = $line;
            },
            static function (string $line) use (&$err): void {
                $err[] = $line;
            },
        );

        self::assertSame(['{"a":1}', '{"b":2}', '{"c":3}'], $out);
        self::assertSame(['e1', 'e2'], $err);
        self::assertSame('', $result->stdout, 'streamed stdout is not collected');
        self::assertSame("e1\ne2", $result->stderr);
    }

    public function testWorkingDirectory(): void
    {
        $dir = realpath($this->tempDir());

        self::assertSame($dir, $this->runner->run(new Command([PHP_BINARY, '-n', '-r', 'echo getcwd();'], cwd: $dir))->stdout);
    }

    public function testArgvIsPassedVerbatimWithoutAShell(): void
    {
        $args = ['$HOME', '`id`', 'a b', "quote'd", '; rm -rf /', '*'];
        $result = $this->runner->run(new Command([PHP_BINARY, '-n', '-r', 'echo json_encode(array_slice($argv, 1));', '--', ...$args]));

        self::assertSame($args, json_decode($result->stdout, true));
    }

    public function testAMissingBinaryIsAClearError(): void
    {
        $this->expectExceptionObject(new ArkhiveException('Cannot run /nonexistent/restic: it is not an executable file'));
        $this->runner->run(new Command(['/nonexistent/restic', 'version']));
    }

    public function testAKilledChildReportsTheSignalAsExitCode(): void
    {
        $result = $this->runner->run(new Command([PHP_BINARY, '-r', 'posix_kill(getmypid(), 9); sleep(5);']));

        self::assertSame(137, $result->exitCode);
    }

    public function testTimeoutSendsSigterm(): void
    {
        $result = $this->runner->run(self::php('echo "started\n"; sleep(30);')->withTimeout(0.3));

        self::assertTrue($result->timedOut);
        self::assertSame(143, $result->exitCode);
        self::assertSame("started\n", $result->stdout);
        self::assertLessThan(5.0, $result->duration);
    }

    public function testTimeoutEscalatesToSigkillWhenSigtermIsIgnored(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('needs ext-pcntl');
        }
        $runner = new SystemProcessRunner($this->signals, null, 0.5);

        $result = $runner->run(new Command([PHP_BINARY, '-r', 'pcntl_signal(SIGTERM, SIG_IGN); echo "ready\n"; while (true) { usleep(10000); }'], timeout: 0.2));
        self::assertTrue($result->timedOut);
        self::assertSame(137, $result->exitCode);
        self::assertGreaterThanOrEqual(0.7, $result->duration);
        self::assertLessThan(5.0, $result->duration);
    }

    public function testASignalIsForwardedToTheChildAndBecomesAnInterruption(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('needs ext-pcntl');
        }
        $marker = $this->tempDir() . '/child-cleaned-up';
        $code = sprintf(
            'pcntl_async_signals(true); pcntl_signal(SIGTERM, function () { file_put_contents(%s, "1"); exit(130); }); fwrite(STDERR, "ready\n"); sleep(30);',
            var_export($marker, true),
        );
        $signals = $this->signals;

        try {
            $this->runner->run(new Command([PHP_BINARY, '-r', $code]), null, static function (string $line) use ($signals): void {
                if ($line === 'ready') {
                    $signals->receive(InterruptedException::SIGTERM);
                }
            });
            self::fail('expected an interruption');
        } catch (InterruptedException $e) {
            self::assertSame(InterruptedException::SIGTERM, $e->signal());
        }
        self::assertFileExists($marker, 'the child received SIGTERM and ran its own cleanup');
    }

    public function testNoProcessStartsOnceInterrupted(): void
    {
        $marker = $this->tempDir() . '/started';
        $this->signals->receive(InterruptedException::SIGINT);

        try {
            $this->runner->run(self::php(sprintf('file_put_contents(%s, "1");', var_export($marker, true))));
            self::fail('expected an interruption');
        } catch (InterruptedException $e) {
            self::assertSame(130, $e->exitCode());
        }
        self::assertFileDoesNotExist($marker);
    }

    public function testARealSignalToArkhiveReachesTheChild(): void
    {
        if (!Signals::supported()) {
            self::markTestSkipped('needs ext-pcntl');
        }
        $this->signals->install();
        $marker = $this->tempDir() . '/child-got-sigint';
        $code = sprintf(
            'pcntl_async_signals(true); pcntl_signal(SIGINT, function () { file_put_contents(%s, "1"); exit(1); }); fwrite(STDERR, "ready\n"); sleep(30);',
            var_export($marker, true),
        );

        try {
            $this->runner->run(new Command([PHP_BINARY, '-r', $code]), null, static function (string $line): void {
                if ($line === 'ready') {
                    posix_kill(getmypid(), SIGINT);
                }
            });
            self::fail('expected an interruption');
        } catch (InterruptedException $e) {
            self::assertSame(130, $e->exitCode());
        } finally {
            $this->signals->uninstall();
        }
        self::assertFileExists($marker);
    }
}
