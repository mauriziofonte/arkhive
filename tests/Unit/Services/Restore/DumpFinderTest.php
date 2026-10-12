<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Restore;

use DateTimeImmutable;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Config\ResticSettings;
use Mfonte\Arkhive\Services\Restic\Node;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restic\Snapshot;
use Mfonte\Arkhive\Services\Restore\DumpFile;
use Mfonte\Arkhive\Services\Restore\DumpFinder;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;

final class DumpFinderTest extends TestCase
{
    public function testFindsTheDumpsBelowEachSnapshotPath(): void
    {
        $runner = new FakeProcessRunner();
        $fake = (new FakeRestic())->withRepository()->install($runner);
        $restic = new Restic(new ResticSettings('/srv/restic-repo', '/etc/arkhive/pw'), '/usr/bin/restic', $runner);
        $snapshot = new Snapshot('6118b3c5', new DateTimeImmutable(), 'web1.example.com', ['/srv/dumps', '/srv/www']);

        $dumps = DumpFinder::find($restic, $snapshot);

        self::assertSame(['ls', '--json', '--', '6118b3c5', '/srv/dumps/mysql', '/srv/dumps/pgsql', '/srv/www/mysql', '/srv/www/pgsql'], $fake->calls[0]);
        self::assertEquals([
            new DumpFile('mysql', 'shop', '/srv/dumps/mysql/shop.sql', 25),
            new DumpFile('pgsql', 'crm', '/srv/dumps/pgsql/crm.sql', 25),
        ], $dumps);
    }

    public function testNamesAreDecodedAndOtherFilesIgnored(): void
    {
        $dirs = ['/var/dumps/mysql', '/var/dumps/pgsql'];
        $now = new DateTimeImmutable();

        self::assertEquals(new DumpFile('mysql', 'my shop/€', '/var/dumps/mysql/my%20shop%2F%E2%82%AC.sql', 9), DumpFinder::fromNode(new Node('/var/dumps/mysql/my%20shop%2F%E2%82%AC.sql', 'file', 9, '-rw-------', $now), $dirs));
        self::assertNull(DumpFinder::fromNode(new Node('/var/dumps/mysql', 'dir', 0, 'drwx------', $now), $dirs));
        self::assertNull(DumpFinder::fromNode(new Node('/var/dumps/mysql/shop.sql.partial', 'file', 9, '-rw-------', $now), $dirs));
        self::assertNull(DumpFinder::fromNode(new Node('/var/dumps/mysql/old/shop.sql', 'file', 9, '-rw-------', $now), $dirs));
        self::assertNull(DumpFinder::fromNode(new Node('/srv/www/mysql/shop.sql', 'file', 9, '-rw-------', $now), $dirs));
    }

    public function testSelect(): void
    {
        $dumps = [
            new DumpFile('mysql', 'shop', '/d/mysql/shop.sql', 1),
            new DumpFile('pgsql', 'shop', '/d/pgsql/shop.sql', 2),
            new DumpFile('pgsql', 'crm', '/d/pgsql/crm.sql', 3),
        ];

        self::assertSame('/d/pgsql/crm.sql', DumpFinder::select($dumps, 'crm')->path);
        self::assertSame('/d/mysql/shop.sql', DumpFinder::select($dumps, 'mysql/shop')->path);
        self::assertSame('/d/pgsql/shop.sql', DumpFinder::select($dumps, 'pgsql/shop')->path);

        foreach ([
            ['shop', $dumps, 'Both a MySQL and a PostgreSQL dump of shop are in the snapshot'],
            ['blog', $dumps, 'There is no dump of database blog in the snapshot (available: mysql/shop, pgsql/shop, pgsql/crm)'],
            ['mysql/crm', $dumps, 'There is no dump of database crm in the snapshot'],
            ['shop', [], 'There is no dump of database shop in the snapshot (the snapshot has no database dump)'],
        ] as [$name, $list, $message]) {
            try {
                DumpFinder::select($list, $name);
                self::fail("{$name} should be refused");
            } catch (UsageException $e) {
                self::assertStringStartsWith($message, $e->getMessage());
            }
        }
    }

    public function testSnapshotWithoutPathsHasNoDumps(): void
    {
        $runner = new FakeProcessRunner();
        $restic = new Restic(new ResticSettings('/r', '/p'), '/usr/bin/restic', $runner);

        self::assertSame([], DumpFinder::find($restic, new Snapshot('a', new DateTimeImmutable(), 'h', [])));
        self::assertSame([], $runner->commands);
    }
}
