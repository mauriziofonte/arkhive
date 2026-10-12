<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Restore;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Process\Cleanup;
use Mfonte\Arkhive\Services\Restore\DatabaseImporter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Configs;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\TempDir;

final class DatabaseImporterTest extends TestCase
{
    use TempDir;

    public function testNamesArkhiveCreates(): void
    {
        foreach (['shop_restored', 'crm-2026', 'A', str_repeat('x', 63), '_x'] as $name) {
            DatabaseImporter::assertValidName($name);
        }
        foreach (['', 'shop restored', 'shop;drop', 'a`b', 'a"b', "a'b", '-x', str_repeat('x', 64), 'naïve', 'db/x'] as $name) {
            try {
                DatabaseImporter::assertValidName($name);
                self::fail("'{$name}' should be refused");
            } catch (UsageException $e) {
                self::assertStringContainsString('is not a database name ArkHive creates', $e->getMessage());
            }
        }
    }

    public function testManualCommandsCarryNoPassword(): void
    {
        $config = Configs::make(Configs::mysql(), Configs::pgsql());

        self::assertSame("mysql -h 'db.example.com' -P 3306 -u 'backup' -p NEW_DATABASE < '/r/shop.sql'", DatabaseImporter::manualCommand('mysql', '/r/shop.sql', $config));
        self::assertSame("mysql NEW_DATABASE < '/r/it'\\''s.sql'", DatabaseImporter::manualCommand('mysql', "/r/it's.sql"));
        self::assertSame("createdb -h 'pg.example.com' -p 5432 -U 'backup' NEW_DATABASE && psql -h 'pg.example.com' -p 5432 -U 'backup' --set=ON_ERROR_STOP=1 --dbname=NEW_DATABASE --file='/r/crm.sql'", DatabaseImporter::manualCommand('pgsql', '/r/crm.sql', $config));
        self::assertStringNotContainsString('placeholder', DatabaseImporter::manualCommand('pgsql', '/r/crm.sql', $config));
    }

    public function testServerErrorsCarryAHint(): void
    {
        $runner = (new FakeProcessRunner())->on('psql', static fn (): array => [2, '', "psql: error: connection to server at \"pg.example.com\" failed: Connection refused\n"]);
        $importer = new DatabaseImporter(Configs::make(pgsql: Configs::pgsql()), 'pgsql', $runner, FakeBinaries::dependencies($this->tempDir(), ['psql']), new Cleanup());

        try {
            $importer->exists('crm_copy');
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertStringStartsWith('Checking whether database crm_copy exists failed (psql exit 2): psql: error: connection to server', $e->getMessage());
            self::assertStringContainsString('CREATEDB', (string) $e->hint());
        }
    }

    public function testCredentialsFileIsRemovedOnClose(): void
    {
        $seen = null;
        $runner = (new FakeProcessRunner())->on('mariadb', static function ($command) use (&$seen): array {
            $seen = substr($command->argv[1], strlen('--defaults-extra-file='));

            return [0, "0\n", ''];
        });
        $cleanup = new Cleanup();
        $importer = new DatabaseImporter(Configs::make(Configs::mysql()), 'mysql', $runner, FakeBinaries::dependencies($this->tempDir(), ['mariadb']), $cleanup);

        self::assertFalse($importer->exists('x'));
        self::assertIsString($seen);
        self::assertFileExists($seen);
        self::assertSame(0600, fileperms($seen) & 0777);
        $importer->close();
        self::assertFileDoesNotExist($seen);
        self::assertSame([], $cleanup->runAll());
    }
}
