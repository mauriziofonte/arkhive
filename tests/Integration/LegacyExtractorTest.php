<?php

declare(strict_types=1);

namespace Tests\Integration;

use Mfonte\Arkhive\Application\Legacy\LegacyArchive;
use Mfonte\Arkhive\Application\Legacy\LegacyExtractor;
use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Application\Restore\TargetDirectory;
use Mfonte\Arkhive\Application\System\BinaryFinder;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Execution\ExecutionContext;
use Mfonte\Arkhive\Domain\Execution\LogFormat;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Logging\LogStream;
use Mfonte\Arkhive\Infrastructure\Process\Signals;
use Mfonte\Arkhive\Infrastructure\Process\SystemProcessRunner;
use Mfonte\Arkhive\Infrastructure\System\OsRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FixedClock;
use Tests\Support\LegacyArchives;
use Tests\Support\TempDir;

/**
 * spec §6 decrypt-legacy against the real openssl, xz and tar, on archives made with the 1.x
 * command line: byte-identical round trips, and every way an old archive can be wrong.
 */
final class LegacyExtractorTest extends TestCase
{
    use TempDir;

    private string $dir;
    private Cleanup $cleanup;
    private LogStream $stream;
    /** @var list<Command> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->cleanup = new Cleanup();
        $this->stream = LogStream::memory();
        mkdir("{$this->dir}/src");
        LegacyArchives::tree("{$this->dir}/src");
    }

    protected function tearDown(): void
    {
        $this->cleanup->runAll();
    }

    /**
     * @return iterable<string, array{'gzip'|'xz'|'none', bool}>
     */
    public static function formats(): iterable
    {
        foreach (['gzip', 'xz', 'none'] as $compression) {
            yield "{$compression} encrypted" => [$compression, true];
            yield "{$compression} plain" => [$compression, false];
        }
    }

    /**
     * @param 'gzip'|'xz'|'none' $compression
     */
    #[DataProvider('formats')]
    public function testRoundTripIsByteIdentical(string $compression, bool $encrypted): void
    {
        $path = LegacyArchives::make("{$this->dir}/src", $this->dir, $compression, $encrypted);
        $archive = LegacyArchive::open($path);
        self::assertSame($encrypted, $archive->encrypted);

        $report = $this->extractor()->extract($archive, new TargetDirectory("{$this->dir}/out"), $encrypted ? LegacyArchives::PASSWORD : null, $this->logger());

        self::assertSame(LegacyArchives::manifest("{$this->dir}/src"), LegacyArchives::manifest("{$this->dir}/out"));
        self::assertSame($compression, $report->compression);
        self::assertSame($encrypted, $report->encrypted);
        self::assertSame(10, $report->entries);
        self::assertGreaterThan(200000, $report->bytes);
        self::assertSame('0700', sprintf('%04o', fileperms("{$this->dir}/out") & 0777), 'the new directory is private');
        $this->assertNoLeftovers();
    }

    public function testThePasswordGoesThroughTheEnvironmentNeverArgv(): void
    {
        $path = LegacyArchives::make("{$this->dir}/src", $this->dir, 'gzip', true);
        $this->extractor()->extract(LegacyArchive::open($path), new TargetDirectory("{$this->dir}/out"), LegacyArchives::PASSWORD, $this->logger());

        $openssl = $this->commands[0];
        self::assertSame('openssl', $openssl->name());
        self::assertSame([...LegacyExtractor::OPENSSL_ARGS, '-in', $path, '-out'], array_slice($openssl->argv, 1, 10));
        self::assertSame(['-pass', 'env:ARKHIVE_CRYPT_PASSWORD'], array_slice($openssl->argv, -2));
        self::assertSame(LegacyArchives::PASSWORD, $openssl->env['ARKHIVE_CRYPT_PASSWORD']);
        foreach ($this->commands as $command) {
            self::assertStringNotContainsString('ss\'$x', $command->display());
        }
        self::assertSame(['tar', 'tar'], array_map(static fn (Command $c): string => $c->name(), array_slice($this->commands, 1)), 'gzip is decompressed in PHP');
    }

    public function testWrongPasswordLeavesNothingBehind(): void
    {
        $path = LegacyArchives::make("{$this->dir}/src", $this->dir, 'gzip', true);
        try {
            $this->extractor()->extract(LegacyArchive::open($path), new TargetDirectory("{$this->dir}/out"), 'not the password', $this->logger());
            self::fail('a wrong password was accepted');
        } catch (ArkhiveException $e) {
            self::assertSame('Cannot decrypt the archive: the password is wrong (openssl: bad decrypt)', $e->getMessage());
            self::assertSame(ExitCode::NO_PERMISSION, $e->exitCode());
        }
        self::assertDirectoryDoesNotExist("{$this->dir}/out");
        $this->assertNoLeftovers();
    }

    public function testDataThatDecryptsButIsNoArchiveBlamesThePassword(): void
    {
        LegacyArchives::encrypt(random_bytes(4096), "{$this->dir}/x.enc.arbk");

        $this->expectExceptionMessage('The decrypted data is neither gzip, xz nor tar: the password is wrong, or this is not an ArkHive 1.x archive');
        $this->extractor()->extract(LegacyArchive::open("{$this->dir}/x.enc.arbk"), new TargetDirectory("{$this->dir}/out"), LegacyArchives::PASSWORD, $this->logger());
    }

    public function testAFileThatIsNoArchiveAtAll(): void
    {
        file_put_contents("{$this->dir}/notes.txt", str_repeat('not an archive ', 100));

        $this->expectExceptionMessage('The archive is neither encrypted nor gzip, xz or tar: this is not an ArkHive 1.x archive');
        $this->extractor()->extract(LegacyArchive::open("{$this->dir}/notes.txt"), new TargetDirectory("{$this->dir}/out"), null, $this->logger());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function damage(): iterable
    {
        yield 'truncated gzip' => ['truncate', 'The gzip data of the archive ends too early: the archive is truncated'];
        yield 'flipped bit' => ['flip', 'The gzip data of the archive is corrupt'];
        yield 'trailing garbage' => ['garbage', 'The archive has unexpected data after the end of its gzip data: it is damaged'];
    }

    #[DataProvider('damage')]
    public function testDamagedGzipIsDetectedAndTheTargetRemoved(string $how, string $message): void
    {
        $path = LegacyArchives::make("{$this->dir}/src", $this->dir, 'gzip', false);
        $data = (string) file_get_contents($path);
        $data = match ($how) {
            'truncate' => substr($data, 0, intdiv(strlen($data), 2)),
            'flip' => substr_replace($data, chr(ord($data[strlen($data) - 6]) ^ 1), -6, 1),
            default => $data . 'garbage',
        };
        file_put_contents($path, $data);

        try {
            $this->extractor()->extract(LegacyArchive::open($path), new TargetDirectory("{$this->dir}/out"), null, $this->logger());
            self::fail('a damaged archive was extracted');
        } catch (ArkhiveException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }
        self::assertDirectoryDoesNotExist("{$this->dir}/out");
        $this->assertNoLeftovers();
    }

    public function testConcatenatedGzipMembersAreAllDecompressed(): void
    {
        $tar = LegacyArchives::rawTar('first.txt', "one\n");
        $second = LegacyArchives::rawTar('second.txt', "two\n");
        // one tar stream split over two gzip members, as `gzip` appended to an existing .gz would make
        $joined = substr($tar, 0, -1024) . $second;
        $half = intdiv(strlen($joined), 2);
        file_put_contents("{$this->dir}/multi.arbk", gzencode(substr($joined, 0, $half)) . gzencode(substr($joined, $half)));

        $report = $this->extractor()->extract(LegacyArchive::open("{$this->dir}/multi.arbk"), new TargetDirectory("{$this->dir}/out"), null, $this->logger());

        self::assertSame(2, $report->entries);
        self::assertSame("two\n", file_get_contents("{$this->dir}/out/second.txt"));
    }

    public function testDamagedXzIsReported(): void
    {
        $path = LegacyArchives::make("{$this->dir}/src", $this->dir, 'xz', false);
        $data = (string) file_get_contents($path);
        file_put_contents($path, substr($data, 0, intdiv(strlen($data), 2)));

        $this->expectExceptionMessageMatches('/^Decompressing the archive failed \(xz exit 1\)/');
        $this->extractor()->extract(LegacyArchive::open($path), new TargetDirectory("{$this->dir}/out"), null, $this->logger());
    }

    public function testMembersEscapingTheTargetAreRefused(): void
    {
        file_put_contents("{$this->dir}/evil.tar", LegacyArchives::rawTar('../escaped.txt', "x\n"));

        try {
            $this->extractor()->extract(LegacyArchive::open("{$this->dir}/evil.tar"), new TargetDirectory("{$this->dir}/out"), null, $this->logger());
            self::fail('a path outside the target was accepted');
        } catch (DataException $e) {
            self::assertSame('The archive contains the path ../escaped.txt, which would be written outside the target: not extracted', $e->getMessage());
        }
        self::assertFileDoesNotExist("{$this->dir}/escaped.txt");
        self::assertDirectoryDoesNotExist("{$this->dir}/out");
    }

    public function testOnlyANewOrEmptyDirectoryIsATarget(): void
    {
        $path = LegacyArchives::make("{$this->dir}/src", $this->dir, 'none', false);
        mkdir("{$this->dir}/full");
        touch("{$this->dir}/full/keep");
        touch("{$this->dir}/afile");
        foreach (["{$this->dir}/full", "{$this->dir}/afile"] as $target) {
            try {
                $this->extractor()->extract(LegacyArchive::open($path), new TargetDirectory($target), null, $this->logger());
                self::fail("{$target} was accepted");
            } catch (RefusedException $e) {
                self::assertSame("{$target} exists and is not an empty directory: decrypt-legacy extracts only into a new or empty one", $e->getMessage());
                self::assertSame('Choose a new directory with --to', $e->hint());
            }
        }
        self::assertSame([], $this->commands, 'nothing ran');

        mkdir("{$this->dir}/empty");
        $this->extractor()->extract(LegacyArchive::open($path), new TargetDirectory("{$this->dir}/empty"), null, $this->logger());
        self::assertFileExists("{$this->dir}/empty/srv/www/site/index.php");
    }

    public function testMissingToolsNameTheirPackage(): void
    {
        $path = LegacyArchives::make("{$this->dir}/src", $this->dir, 'gzip', true);
        $extractor = new LegacyExtractor($this->runner(), FakeBinaries::dependencies("{$this->dir}/bin", ['tar']), $this->cleanup);

        try {
            $extractor->extract(LegacyArchive::open($path), new TargetDirectory("{$this->dir}/out"), LegacyArchives::PASSWORD, $this->logger());
            self::fail('openssl was not required');
        } catch (ArkhiveException $e) {
            self::assertSame('openssl is needed to decrypt a 1.x archive but was not found in PATH or the standard directories', $e->getMessage());
            self::assertSame('Run: apt install openssl', $e->hint());
        }

        $extractor = new LegacyExtractor($this->runner(), FakeBinaries::dependencies("{$this->dir}/bin2", []), $this->cleanup);
        $this->expectExceptionMessage('tar is needed to extract a 1.x archive');
        $extractor->extract(LegacyArchive::open($path), new TargetDirectory("{$this->dir}/out"), LegacyArchives::PASSWORD, $this->logger());
    }

    public function testArchiveFileChecksAndDefaultName(): void
    {
        foreach (['2024-05-01-web-arkhive.enc.arbk', '2024-05-01-web-arkhive.arbk.xz', '2024-05-01-web-arkhive.enc.tar', '2024-05-01-web-arkhive.arbk'] as $name) {
            file_put_contents("{$this->dir}/{$name}", 'x');
            self::assertSame('2024-05-01-web-arkhive', LegacyArchive::open("{$this->dir}/{$name}")->baseName(), $name);
        }
        file_put_contents("{$this->dir}/backup.bin", 'x');
        self::assertSame('backup.bin.extracted', LegacyArchive::open("{$this->dir}/backup.bin")->baseName());

        touch("{$this->dir}/empty.arbk");
        foreach (["{$this->dir}/missing.arbk" => ['does not exist', ExitCode::NO_INPUT], $this->dir => ['is not a regular file', ExitCode::NO_INPUT], "{$this->dir}/empty.arbk" => ['is empty', ExitCode::DATA_ERROR]] as $path => [$problem, $exit]) {
            try {
                LegacyArchive::open($path);
                self::fail("{$path} was accepted");
            } catch (ArkhiveException $e) {
                self::assertSame("The archive {$path} {$problem}", $e->getMessage());
                self::assertSame($exit, $e->exitCode());
            }
        }
    }

    private function extractor(): LegacyExtractor
    {
        return new LegacyExtractor($this->runner(), new Dependencies(BinaryFinder::fromEnvironment(), OsRelease::detect()), $this->cleanup);
    }

    private function runner(): ProcessRunner
    {
        $inner = new SystemProcessRunner(new Signals());
        $commands = &$this->commands;

        return new class ($inner, $commands) implements ProcessRunner {
            /**
             * @param list<Command> $commands
             */
            public function __construct(private readonly ProcessRunner $inner, private array &$commands)
            {
            }

            public function run(Command $command, ?callable $onStdoutLine = null, ?callable $onStderrLine = null): ProcessResult
            {
                $this->commands[] = $command;

                return $this->inner->run($command, $onStdoutLine, $onStderrLine);
            }
        };
    }

    private function logger(): Logger
    {
        return new Logger(new ExecutionContext(false, false, false, LogFormat::Plain, 'test'), $this->stream->resource(), null, new FixedClock());
    }

    private function assertNoLeftovers(): void
    {
        self::assertSame([], glob("{$this->dir}/.arkhive-legacy-*") ?: [], 'the temporary directory is removed');
        self::assertSame(0, $this->cleanup->pending());
    }
}
