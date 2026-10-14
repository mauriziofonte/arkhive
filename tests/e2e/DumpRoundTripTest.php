<?php

declare(strict_types=1);

namespace Tests\e2e;

use Mfonte\Arkhive\Services\Config\MysqlConfig;
use Mfonte\Arkhive\Services\Config\PgsqlConfig;
use Mfonte\Arkhive\Services\Dump\DumpStaging;
use Mfonte\Arkhive\Services\Dump\MysqlDumper;
use Mfonte\Arkhive\Services\Dump\PgsqlDumper;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Signals;
use Mfonte\Arkhive\Services\Process\SystemProcessRunner;
use Mfonte\Arkhive\Services\System\BinaryFinder;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\System\Distro;

/**
 * Real dumps from the seeded MariaDB and PostgreSQL servers, imported into fresh databases: rows,
 * checksums and routines must match (spec §13.2 step 6), with passwords that need escaping, and no
 * password ever visible in the process list while the dumps run (step 11).
 */
final class DumpRoundTripTest extends E2eTestCase
{
    use DatabaseClients;

    /** characters that break naive option files, pgpass files and URLs */
    private const TRICKY_PASSWORD = 'p"a\\ss\'#w:r d$x;@/';

    private SystemProcessRunner $runner;
    private BinaryFinder $finder;
    private Dependencies $dependencies;
    private Cleanup $cleanup;

    protected function setUp(): void
    {
        $this->runner = new SystemProcessRunner(new Signals());
        $this->finder = BinaryFinder::fromEnvironment();
        $this->dependencies = new Dependencies($this->finder, Distro::detect());
        $this->cleanup = new Cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup->runAll();
    }

    public function testMysqlDumpsImportIntoFreshDatabasesWithIdenticalData(): void
    {
        $staging = new DumpStaging($this->tempDir() . '/dumps');
        $staging->prepare();
        $dumper = new MysqlDumper($this->mysqlRoot(), $this->runner, $this->dependencies, $this->cleanup);

        $databases = $dumper->databases();
        self::assertContains('shop', $databases);
        self::assertContains('blog', $databases);
        self::assertNotContains('mysql', $databases);
        self::assertNotContains('information_schema', $databases);
        self::assertGreaterThan(0, $dumper->estimate(['shop', 'blog']));

        $tables = ['shop' => ['products', 'orders'], 'blog' => ['posts']];
        foreach ($tables as $database => $names) {
            $result = $dumper->dump($database, $staging);
            $copy = 'e2e_restore_' . $database . '_' . bin2hex(random_bytes(3));
            $this->mysql("CREATE DATABASE {$copy}");
            try {
                $this->mysql('', $copy, $result->path);
                foreach ($names as $table) {
                    self::assertSame($this->mysql("SELECT COUNT(*) FROM {$database}.{$table}"), $this->mysql("SELECT COUNT(*) FROM {$copy}.{$table}"), "{$database}.{$table} rows");
                    self::assertNotSame('', $this->checksum("{$database}.{$table}"));
                    self::assertSame($this->checksum("{$database}.{$table}"), $this->checksum("{$copy}.{$table}"), "{$database}.{$table} checksum");
                }
            } finally {
                $this->mysql("DROP DATABASE {$copy}");
            }
        }
        $dumper->close();
    }

    public function testMysqlRoutinesAndBinaryDataSurvive(): void
    {
        $staging = new DumpStaging($this->tempDir() . '/dumps');
        $staging->prepare();
        $dumper = new MysqlDumper($this->mysqlRoot(), $this->runner, $this->dependencies, $this->cleanup);
        $result = $dumper->dump('shop', $staging);
        $dumper->close();
        $copy = 'e2e_routines_' . bin2hex(random_bytes(3));
        $this->mysql("CREATE DATABASE {$copy}");
        try {
            $this->mysql('', $copy, $result->path);
            self::assertSame('1', $this->mysql("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = '{$copy}' AND routine_name = 'order_count'"));
            self::assertSame(
                $this->mysql('SELECT HEX(payload) FROM shop.products WHERE id = 42'),
                $this->mysql("SELECT HEX(payload) FROM {$copy}.products WHERE id = 42"),
            );
        } finally {
            $this->mysql("DROP DATABASE {$copy}");
        }
    }

    public function testPgsqlDumpsImportIntoFreshDatabasesWithIdenticalData(): void
    {
        $staging = new DumpStaging($this->tempDir() . '/dumps');
        $staging->prepare();
        $dumper = new PgsqlDumper($this->pgsqlRoot(), $this->runner, $this->dependencies, $this->cleanup);
        self::assertNull($dumper->versionWarning());
        self::assertGreaterThan(0, $dumper->estimate(['inventory', 'crm']));

        foreach (['inventory' => 'items', 'crm' => 'contacts'] as $database => $table) {
            $result = $dumper->dump($database, $staging);
            $copy = 'e2e_restore_' . $database . '_' . bin2hex(random_bytes(3));
            $this->psql("CREATE DATABASE {$copy}");
            try {
                $this->psql('', $copy, $result->path);
                $check = "SELECT COUNT(*) || ':' || md5(string_agg(t::text, ',' ORDER BY t.id)) FROM {$table} t";
                self::assertSame($this->psql($check, $database), $this->psql($check, $copy), "{$database}.{$table}");
            } finally {
                $this->psql("DROP DATABASE {$copy}");
            }
        }
        $dumper->close();
    }

    public function testPasswordsThatNeedEscapingWorkAndNeverAppearInTheProcessList(): void
    {
        $sqlPassword = str_replace(['\\', "'"], ['\\\\', "\\'"], self::TRICKY_PASSWORD);
        $this->mysql("CREATE USER IF NOT EXISTS 'e2e_tricky'@'%' IDENTIFIED BY '{$sqlPassword}'");
        $this->mysql("ALTER USER 'e2e_tricky'@'%' IDENTIFIED BY '{$sqlPassword}'");
        $this->mysql("GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON *.* TO 'e2e_tricky'@'%'");
        $pgPassword = str_replace("'", "''", self::TRICKY_PASSWORD);
        if ($this->psql("SELECT COUNT(*) FROM pg_roles WHERE rolname = 'e2e_tricky'") === '0') {
            $this->psql('CREATE ROLE e2e_tricky LOGIN');
        }
        $this->psql("ALTER ROLE e2e_tricky PASSWORD '{$pgPassword}'");
        $this->psql('GRANT pg_read_all_data TO e2e_tricky');

        $samples = $this->tempDir() . '/samples';
        $sampler = new ProcessSampler($samples);
        try {
            $staging = new DumpStaging($this->tempDir() . '/dumps');
            $staging->prepare();
            $mysql = new MysqlDumper(
                new MysqlConfig(self::env('E2E_MYSQL_HOST'), (int) self::env('E2E_MYSQL_PORT'), 'e2e_tricky', self::TRICKY_PASSWORD, ['*']),
                $this->runner,
                $this->dependencies,
                $this->cleanup,
            );
            foreach ($mysql->databases() as $database) {
                self::assertGreaterThan(0, $mysql->dump($database, $staging)->bytes);
            }
            $mysql->close();
            $pg = new PgsqlDumper(
                new PgsqlConfig(self::env('E2E_PGSQL_HOST'), (int) self::env('E2E_PGSQL_PORT'), 'e2e_tricky', self::TRICKY_PASSWORD, ['inventory', 'crm'], ['--no-owner', '--no-privileges']),
                $this->runner,
                $this->dependencies,
                $this->cleanup,
            );
            foreach ($pg->databases() as $database) {
                self::assertGreaterThan(0, $pg->dump($database, $staging)->bytes);
            }
            $pg->close();
        } finally {
            $seen = $sampler->stop();
        }

        self::assertNotSame('', $seen, 'the sampler saw no process at all');
        self::assertMatchesRegularExpression('/(mariadb-dump|mysqldump|pg_dump)/', $seen, 'the sampler caught at least one dump process');
        self::assertStringNotContainsString(self::TRICKY_PASSWORD, $seen);
        self::assertStringNotContainsString('p"a', $seen);
        self::assertStringNotContainsString(self::env('E2E_MYSQL_PASSWORD'), $seen);
        self::assertStringNotContainsString(self::env('E2E_PGSQL_PASSWORD'), $seen);
    }
}
