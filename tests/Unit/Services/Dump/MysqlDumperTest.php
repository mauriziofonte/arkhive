<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dump;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Services\Config\MysqlConfig;
use Mfonte\Arkhive\Services\Dump\DumpStaging;
use Mfonte\Arkhive\Services\Dump\MysqlDumper;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Command;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\TempDir;

final class MysqlDumperTest extends TestCase
{
    use TempDir;

    private const PASSWORD = "p\"a\\ss'#w:r d\$x;\n\t";

    private FakeProcessRunner $runner;
    private Cleanup $cleanup;
    private string $bin;
    private DumpStaging $staging;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->cleanup = new Cleanup();
        $this->bin = $this->tempDir() . '/bin';
        $this->staging = new DumpStaging($this->tempDir() . '/dumps');
        $this->staging->prepare();
    }

    /**
     * @param list<string> $databases
     * @param list<string> $binaries
     */
    private function dumper(array $databases = ['shop'], array $binaries = ['mariadb-dump', 'mariadb'], ?string $password = self::PASSWORD): MysqlDumper
    {
        return new MysqlDumper(
            new MysqlConfig('db.example.com', 3307, 'backup', $password, $databases),
            $this->runner,
            FakeBinaries::dependencies($this->bin, $binaries),
            $this->cleanup,
        );
    }

    private static function credentialsPath(Command $command): string
    {
        self::assertStringStartsWith('--defaults-extra-file=', $command->argv[1], 'the option file must be the first option');

        return substr($command->argv[1], strlen('--defaults-extra-file='));
    }

    public function testOptionFileEscapesEveryCharacterThatMatters(): void
    {
        self::assertSame(
            "[client]\nhost=\"db.example.com\"\nport=3307\nuser=\"backup\"\npassword=\"p\\\"a\\\\ss'#w:r d\$x;\\n\\t\"\n",
            MysqlDumper::optionFile(new MysqlConfig('db.example.com', 3307, 'backup', self::PASSWORD, ['shop'])),
        );
        self::assertSame(
            "[client]\nhost=\"localhost\"\nport=3306\nuser=\"root\"\n",
            MysqlDumper::optionFile(new MysqlConfig('localhost', 3306, 'root', null, ['*'])),
        );
    }

    public function testDumpUsesTheOptionFileAndTheSpecOptions(): void
    {
        $seen = null;
        $this->runner->on('mariadb-dump', function (Command $command) use (&$seen): int {
            $credentials = self::credentialsPath($command);
            $seen = [file_get_contents($credentials), fileperms($credentials) & 0777];
            $resultFile = substr($command->argv[8], strlen('--result-file='));
            file_put_contents($resultFile, "-- dump\nCREATE TABLE t (id INT);\n");

            return 0;
        });

        $dumper = $this->dumper();
        $result = $dumper->dump('shop', $this->staging);

        $command = $this->runner->commands[0];
        $credentials = self::credentialsPath($command);
        self::assertSame([
            "{$this->bin}/mariadb-dump",
            "--defaults-extra-file={$credentials}",
            '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob',
            '--result-file=' . $this->staging->directory . '/mysql/shop.sql.partial',
            '--',
            'shop',
        ], $command->argv);
        self::assertSame([MysqlDumper::optionFile(new MysqlConfig('db.example.com', 3307, 'backup', self::PASSWORD, ['shop'])), 0600], $seen);
        self::assertSame('mysql', $result->engine);
        self::assertSame('shop', $result->database);
        self::assertSame($this->staging->directory . '/mysql/shop.sql', $result->path);
        self::assertSame(strlen("-- dump\nCREATE TABLE t (id INT);\n"), $result->bytes);
        self::assertFileDoesNotExist($this->staging->directory . '/mysql/shop.sql.partial');
        self::assertStringNotContainsString('p"a', $this->runner->everythingOnTheCommandLine());

        self::assertFileExists($credentials, 'kept for the next database');
        $dumper->close();
        self::assertFileDoesNotExist($credentials);
        self::assertSame(0, $this->cleanup->pending());
        $dumper->close();
    }

    public function testMysqldumpIsUsedWhenMariadbDumpIsMissing(): void
    {
        $this->runner->on('mysqldump', static function (Command $command): int {
            file_put_contents(substr($command->argv[8], strlen('--result-file=')), 'x');

            return 0;
        });

        $this->dumper(['shop'], ['mysqldump', 'mysql'])->dump('shop', $this->staging);

        self::assertSame("{$this->bin}/mysqldump", $this->runner->commands[0]->argv[0]);
    }

    public function testAFailedDumpRemovesThePartialFileAndKeepsStderr(): void
    {
        $this->runner->on('mariadb-dump', static function (Command $command): array {
            file_put_contents(substr($command->argv[8], strlen('--result-file=')), 'half a dump');

            return [2, '', "mariadb-dump: Got error: 1045: \"Access denied for user 'backup'@'10.0.0.5' (using password: YES)\" when trying to connect\n"];
        });

        try {
            $this->dumper()->dump('shop', $this->staging);
            self::fail('expected the dump to fail');
        } catch (ArkhiveException $e) {
            self::assertSame(
                "MySQL dump of database shop failed (mariadb-dump exit 2): mariadb-dump: Got error: 1045: \"Access denied for user 'backup'@'10.0.0.5' (using password: YES)\" when trying to connect",
                $e->getMessage(),
            );
        }
        self::assertSame([], glob($this->staging->directory . '/mysql/*') ?: []);
    }

    public function testSuccessWithoutAFileIsAnError(): void
    {
        $this->runner->on('mariadb-dump', static fn (): int => 0);

        $this->expectExceptionObject(new ArkhiveException('MySQL dump of database shop reported success but wrote no file at ' . $this->staging->directory . '/mysql/shop.sql.partial'));
        $this->dumper()->dump('shop', $this->staging);
    }

    public function testStarExpandsToEveryDatabaseButTheSystemSchemas(): void
    {
        $this->runner->on('mariadb', static fn (): array => [0, "information_schema\nblog\nmysql\nperformance_schema\nshop\nsys\n", '']);

        self::assertSame(['blog', 'shop'], $this->dumper(['*'])->databases());
        $command = $this->runner->commands[0];
        self::assertSame(['--connect-timeout=10', '--batch', '--skip-column-names', '--raw', '--execute=SHOW DATABASES'], array_slice($command->argv, 2));
    }

    public function testSystemSchemasListedNextToStarAreKept(): void
    {
        $this->runner->on('mariadb', static fn (): array => [0, "blog\nmysql\nsys\n", '']);

        self::assertSame(['blog', 'mysql', 'archive'], $this->dumper(['*', 'mysql', 'archive'])->databases());
    }

    public function testAnExplicitListIsUsedAsIsWithoutQueryingTheServer(): void
    {
        self::assertSame(['shop', 'mysql'], $this->dumper(['shop', 'mysql'])->databases());
        self::assertSame([], $this->runner->commands);
    }

    public function testQueryFailureNamesTheSettingsToCheck(): void
    {
        $this->runner->on('mariadb', static fn (): array => [1, '', "ERROR 2002 (HY000): Can't connect to server on 'db.example.com' (115)\n"]);

        try {
            $this->dumper(['*'])->databases();
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame("Listing the MySQL databases failed (mariadb exit 1): ERROR 2002 (HY000): Can't connect to server on 'db.example.com' (115)", $e->getMessage());
            self::assertSame('Check MYSQL_HOST, MYSQL_PORT, MYSQL_USER and MYSQL_PASSWORD, and that the server is running', $e->hint());
        }
    }

    public function testEstimateQuotesDatabaseNames(): void
    {
        $this->runner->on('mariadb', static fn (): array => [0, "123456789\n", '']);

        self::assertSame(123456789, $this->dumper()->estimate(['shop', "o'brien\\x"]));
        self::assertSame(
            "--execute=SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema IN ('shop','o\\'brien\\\\x')",
            $this->runner->commands[0]->argv[6],
        );
        self::assertSame(0, $this->dumper()->estimate([]));
    }

    public function testNoPasswordMeansNoPasswordLine(): void
    {
        $this->runner->on('mariadb', static function (Command $command): array {
            self::assertStringNotContainsString('password', (string) file_get_contents(self::credentialsPath($command)));

            return [0, "shop\n", ''];
        });

        self::assertSame(['shop'], $this->dumper(['*'], password: null)->databases());
    }

    public function testDatabaseNamesBecomeSafeFileNames(): void
    {
        $this->runner->on('mariadb-dump', static function (Command $command): int {
            file_put_contents(substr($command->argv[8], strlen('--result-file=')), 'x');

            return 0;
        });

        $result = $this->dumper()->dump('my db/../x', $this->staging);

        self::assertSame($this->staging->directory . '/mysql/my%20db%2F..%2Fx.sql', $result->path);
        self::assertSame('my db/../x', $this->runner->commands[0]->argv[10]);
    }

    public function testInterruptedRunsRemoveTheCredentialsThroughTheCleanupRegistry(): void
    {
        $credentials = null;
        $this->runner->on('mariadb', static function (Command $command) use (&$credentials): array {
            $credentials = self::credentialsPath($command);

            return [0, "shop\n", ''];
        });
        $this->dumper(['*'])->databases();

        self::assertIsString($credentials);
        self::assertFileExists($credentials);
        self::assertSame([], $this->cleanup->runAll());
        self::assertFileDoesNotExist($credentials);
    }
}
