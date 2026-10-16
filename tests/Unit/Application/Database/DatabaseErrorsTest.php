<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Database;

use Mfonte\Arkhive\Application\Database\DatabaseErrors;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Exceptions\StalledException;
use Mfonte\Arkhive\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseErrorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function failures(): iterable
    {
        yield 'mariadb server down' => ["mariadb-dump: Got error: 2002: \"Can't connect to server on '127.0.0.1' (115)\" when trying to connect\n", ExitCode::UNAVAILABLE];
        yield 'mysql unknown host' => ["mysqldump: Got error: 2005: \"Unknown MySQL server host 'db.example.com' (-2)\" when trying to connect\n", ExitCode::UNAVAILABLE];
        yield 'mysql connection lost' => ["mysqldump: Error 2013: Lost connection to server during query when dumping table `orders`\n", ExitCode::UNAVAILABLE];
        yield 'postgres server down' => ["pg_dump: error: connection to server at \"127.0.0.1\", port 1 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?\n", ExitCode::UNAVAILABLE];
        yield 'postgres unknown host' => ["pg_dump: error: could not translate host name \"db.example.com\" to address: Name or service not known\n", ExitCode::UNAVAILABLE];
        yield 'mysql wrong password' => ["mysqldump: Got error: 1045: \"Access denied for user 'backup'@'localhost' (using password: YES)\" when trying to connect\n", ExitCode::NO_PERMISSION];
        yield 'postgres wrong password' => ["pg_dump: error: connection to server at \"127.0.0.1\", port 5432 failed: FATAL:  password authentication failed for user \"backup\"\n", ExitCode::NO_PERMISSION];
        yield 'postgres unknown role' => ["pg_dump: error: connection to server on socket \"/run/postgresql/.s.PGSQL.5432\" failed: FATAL:  role \"backup\" does not exist\n", ExitCode::NO_PERMISSION];
        yield 'postgres pg_hba' => ["pg_dump: error: connection to server at \"10.0.0.5\", port 5432 failed: FATAL:  no pg_hba.conf entry for host \"10.0.0.9\"\n", ExitCode::NO_PERMISSION];
        yield 'mysql unknown database' => ["mysqldump: Got error: 1049: \"Unknown database 'shop'\" when selecting the database\n", ExitCode::NO_INPUT];
        yield 'postgres unknown database' => ["pg_dump: error: connection to server at \"127.0.0.1\", port 5432 failed: FATAL:  database \"shop\" does not exist\n", ExitCode::NO_INPUT];
        yield 'disk full' => ["mysqldump: Error: 'Error on write of '/var/backups/shop.sql' (Errcode: 28 \"No space left on device\")'\n", ExitCode::IO_ERROR];
        yield 'anything else' => ["pg_dump: error: query failed: ERROR:  permission denied for table orders\n", ExitCode::FAILURE];
    }

    #[DataProvider('failures')]
    public function testTheCauseDecidesTheExitCode(string $stderr, int $exit): void
    {
        $exception = DatabaseErrors::exception(new ProcessResult(new Command(['/usr/bin/pg_dump']), 1, '', $stderr), 'PostgreSQL dump of database shop');

        self::assertSame($exit, $exception->exitCode());
        self::assertStringStartsWith('PostgreSQL dump of database shop failed (pg_dump exit 1): ', $exception->getMessage());
        self::assertSame($exit !== ExitCode::FAILURE, $exception->hint() !== null);
        self::assertSame($exit === ExitCode::UNAVAILABLE, $exception->transient(), 'only an unreachable server may answer later');
    }

    public function testATimeoutIsTemporary(): void
    {
        $command = (new Command(['/usr/bin/mysqldump']))->withTimeout(30.0);
        $exception = DatabaseErrors::exception(new ProcessResult($command, 137, '', "Can't connect to server\n", 30.0, true), 'MySQL dump of database shop');

        self::assertSame(ExitCode::TEMPORARY, $exception->exitCode());
        self::assertStringContainsString('timed out after 30s', $exception->getMessage());
    }

    public function testADumpThatMadeNoProgressIsTemporaryAndSaysSo(): void
    {
        $command = (new Command(['/usr/bin/mysqldump']))->withStallTimeout(300.0, '/var/backups/dumps/mysql/shop.sql.partial');
        $exception = DatabaseErrors::exception(new ProcessResult($command, 143, '', '', 300.4, true, null, true), 'MySQL dump of database shop');

        self::assertInstanceOf(StalledException::class, $exception);
        self::assertSame(ExitCode::TEMPORARY, $exception->exitCode());
        self::assertTrue($exception->transient());
        self::assertSame('MySQL dump of database shop made no progress for 300s and was stopped (mysqldump)', $exception->getMessage());
        self::assertSame('The database server stopped sending data: check that it is running, not paused and not blocked by a lock, then run again (DUMP_STALL_TIMEOUT sets how long a dump may go without progress)', $exception->hint());
    }

    public function testTheCallersHintWins(): void
    {
        $result = new ProcessResult(new Command(['/usr/bin/pg_dump']), 1, '', "pg_dump: error: server version mismatch\n");

        self::assertSame('use a newer pg_dump', DatabaseErrors::exception($result, 'dump', 'use a newer pg_dump')->hint());
        self::assertSame('use a newer pg_dump', DatabaseErrors::exception(new ProcessResult(new Command(['/usr/bin/pg_dump']), 1, '', 'Connection refused'), 'dump', 'use a newer pg_dump')->hint());
    }
}
