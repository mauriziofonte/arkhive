<?php

declare(strict_types=1);

namespace Tests\e2e;

use Mfonte\Arkhive\Services\Config\MysqlConfig;
use Mfonte\Arkhive\Services\Config\PgsqlConfig;
use Mfonte\Arkhive\Services\Dump\DumpStaging;
use Mfonte\Arkhive\Services\Dump\MysqlDumper;
use Mfonte\Arkhive\Services\Dump\PgsqlDumper;
use Mfonte\Arkhive\Services\Dump\SecretFile;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Process\Command;
use Mfonte\Arkhive\Services\Process\Signals;
use Mfonte\Arkhive\Services\Process\SystemProcessRunner;
use Mfonte\Arkhive\Services\System\BinaryFinder;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\System\Distro;
use Tests\Support\TempDir;

/**
 * Real dumps from the seeded MariaDB and PostgreSQL servers, imported into fresh databases: rows,
 * checksums and routines must match (spec §13.2 step 6), with passwords that need escaping, and no
 * password ever visible in the process list while the dumps run (step 11).
 */
final class DumpRoundTripTest extends E2eTestCase
{
    use TempDir;

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

    private function mysqlRoot(): MysqlConfig
    {
        return new MysqlConfig(self::env('E2E_MYSQL_HOST'), (int) self::env('E2E_MYSQL_PORT'), self::env('E2E_MYSQL_USER'), self::env('E2E_MYSQL_PASSWORD'), ['*']);
    }

    private function pgsqlRoot(): PgsqlConfig
    {
        return new PgsqlConfig(self::env('E2E_PGSQL_HOST'), (int) self::env('E2E_PGSQL_PORT'), self::env('E2E_PGSQL_USER'), self::env('E2E_PGSQL_PASSWORD'), ['inventory', 'crm'], ['--no-owner', '--no-privileges']);
    }

    private function mysql(string $sql, ?string $database = null, ?string $inputFile = null): string
    {
        $credentials = SecretFile::create('e2e-mysql-', MysqlDumper::optionFile($this->mysqlRoot()));
        try {
            $argv = [(string) $this->finder->first(['mariadb', 'mysql']), '--defaults-extra-file=' . $credentials->path, '--batch', '--skip-column-names'];
            if ($inputFile === null) {
                $argv[] = '--execute=' . $sql;
            }
            if ($database !== null) {
                $argv[] = $database;
            }
            $result = $this->runner->run(new Command($argv, input: $inputFile === null ? null : (string) file_get_contents($inputFile)));
            self::assertSame(0, $result->exitCode, $result->stderr);

            return trim($result->stdout);
        } finally {
            $credentials->remove();
        }
    }

    /**
     * CHECKSUM TABLE value (the table name column dropped).
     */
    private function checksum(string $table): string
    {
        $row = explode("\t", $this->mysql("CHECKSUM TABLE {$table}"));

        return $row[1] ?? '';
    }

    private function psql(string $sql, string $database = 'postgres', ?string $file = null): string
    {
        $config = $this->pgsqlRoot();
        $passfile = SecretFile::create('e2e-pgpass-', PgsqlDumper::passfileLine($config));
        try {
            $result = $this->runner->run(new Command([
                (string) $this->finder->find('psql'),
                "--host={$config->host}", "--port={$config->port}", "--username={$config->user}", '--no-password',
                "--dbname={$database}", '--no-psqlrc', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1', '--quiet',
                ...($file === null ? ["--command={$sql}"] : ["--file={$file}"]),
            ], ['PGPASSFILE' => $passfile->path]));
            self::assertSame(0, $result->exitCode, $result->stderr);

            return trim($result->stdout);
        } finally {
            $passfile->remove();
        }
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
        $sampler = self::startSampler($samples);
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
            $seen = self::stopSampler($sampler, $samples);
        }

        self::assertNotSame('', $seen, 'the sampler saw no process at all');
        self::assertMatchesRegularExpression('/(mariadb-dump|mysqldump|pg_dump)/', $seen, 'the sampler caught at least one dump process');
        self::assertStringNotContainsString(self::TRICKY_PASSWORD, $seen);
        self::assertStringNotContainsString('p"a', $seen);
        self::assertStringNotContainsString(self::env('E2E_MYSQL_PASSWORD'), $seen);
        self::assertStringNotContainsString(self::env('E2E_PGSQL_PASSWORD'), $seen);
    }

    /**
     * A separate PHP process that records the command line of every process, every millisecond.
     *
     * @return resource
     */
    private static function startSampler(string $output)
    {
        $code = <<<'PHP'
            $out = fopen($argv[1], 'w');
            $seen = [];
            while (!file_exists($argv[1] . '.stop')) {
                foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                    $cmdline = @file_get_contents($file);
                    if (is_string($cmdline) && $cmdline !== '' && !isset($seen[$cmdline])) {
                        $seen[$cmdline] = true;
                        fwrite($out, str_replace("\0", ' ', $cmdline) . "\n");
                    }
                }
                usleep(1000);
            }
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $output], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        for ($i = 0; $i < 200 && !file_exists($output); $i++) {
            usleep(10000);
        }

        return $process;
    }

    /**
     * @param resource $process
     */
    private static function stopSampler($process, string $output): string
    {
        touch($output . '.stop');
        proc_close($process);

        return (string) file_get_contents($output);
    }
}
