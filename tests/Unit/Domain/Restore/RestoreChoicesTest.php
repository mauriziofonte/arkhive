<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Restore;

use DateTimeImmutable;
use Mfonte\Arkhive\Application\Restore\SizeEstimator;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Domain\Restore\DumpFile;
use Mfonte\Arkhive\Domain\Restore\OwnerMode;
use Mfonte\Arkhive\Domain\Restore\RestoreOptions;
use Mfonte\Arkhive\Domain\Restore\RestorePlan;
use Mfonte\Arkhive\Domain\Restore\Selection;
use Mfonte\Arkhive\Domain\Restore\SelectionKind;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Restic\Restic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;

/**
 * Flags, selections, ownership and the size of a selection.
 */
final class RestoreChoicesTest extends TestCase
{
    public function testOwnerDefaultsToPreserveForRootOnly(): void
    {
        self::assertSame(OwnerMode::Preserve, OwnerMode::default(0));
        self::assertSame(OwnerMode::Current, OwnerMode::default(1000));
        self::assertSame(OwnerMode::Current, OwnerMode::fromOption('current', 0));
        self::assertSame(OwnerMode::Preserve, OwnerMode::fromOption(' PRESERVE ', 0));
        self::assertSame('you (uid 1000); original owners are not restored', OwnerMode::Current->describe(1000));
        self::assertSame('root (--owner current)', OwnerMode::Current->describe(0));
        self::assertSame('original owners (as in the snapshot)', OwnerMode::Preserve->describe(0));
    }

    public function testOwnerPreserveNeedsRoot(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('--owner preserve needs root: running as uid 1000');
        OwnerMode::fromOption('preserve', 1000);
    }

    public function testOwnerMustBeKnown(): void
    {
        $this->expectExceptionMessage("--owner must be preserve or current, not 'mine'");
        OwnerMode::fromOption('mine', 0);
    }

    /**
     * @return iterable<string, array{RestoreOptions, string}>
     */
    public static function conflicts(): iterable
    {
        yield 'include and db' => [new RestoreOptions(includes: ['/a'], db: 'shop'), '--include/--exclude and --db cannot be combined'];
        yield 'exclude and stdout' => [new RestoreOptions(excludes: ['*.log'], toStdout: '/a'), '--include/--exclude and --to-stdout cannot be combined'];
        yield 'db and stdout' => [new RestoreOptions(db: 'shop', toStdout: '/a'), '--db and --to-stdout cannot be combined'];
        yield 'import without db' => [new RestoreOptions(importAs: 'x'), '--import-as needs --db'];
        yield 'in place with target' => [new RestoreOptions(target: '/t', inPlace: true), '--in-place cannot be combined with --target'];
        yield 'in place with db' => [new RestoreOptions(db: 'shop', inPlace: true), '--in-place cannot be combined with --db'];
        yield 'in place with stdout' => [new RestoreOptions(toStdout: '/a', inPlace: true), '--in-place cannot be combined with --to-stdout'];
        yield 'target with stdout' => [new RestoreOptions(target: '/t', toStdout: '/a'), '--target cannot be combined with --to-stdout'];
        yield 'target with import' => [new RestoreOptions(target: '/t', db: 'shop', importAs: 'x'), '--target cannot be combined with --import-as'];
    }

    #[DataProvider('conflicts')]
    public function testConflictingFlagsAreUsageErrors(RestoreOptions $options, string $message): void
    {
        try {
            $options->validate();
            self::fail('expected a usage error');
        } catch (UsageException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
            self::assertSame(ExitCode::USAGE, $e->exitCode());
        }
    }

    public function testValidCombinations(): void
    {
        foreach ([
            new RestoreOptions(),
            new RestoreOptions(includes: ['/a'], excludes: ['*.log'], target: '/t'),
            new RestoreOptions(db: 'shop', importAs: 'shop_new'),
            new RestoreOptions(db: 'shop', target: '/t'),
            new RestoreOptions(includes: ['/a'], inPlace: true, force: true),
        ] as $options) {
            $options->validate();
        }
        self::assertFalse((new RestoreOptions(target: '/t'))->hasSelection());
        self::assertTrue((new RestoreOptions(excludes: ['x']))->hasSelection());
        self::assertTrue((new RestoreOptions(inPlace: true))->hasSelection());
    }

    public function testSelections(): void
    {
        $dump = new DumpFile('pgsql', 'crm', '/srv/dumps/pgsql/crm.sql', 2048);

        self::assertSame(SelectionKind::Everything, Selection::paths([])->kind, 'no include = everything');
        self::assertSame('everything, except *.log', Selection::everything(['*.log'])->describe());
        self::assertSame('/srv/www/site, /etc/nginx, except *.tmp', Selection::paths(['/srv/www/site', '/etc/nginx'], ['*.tmp'])->describe());
        self::assertSame('PostgreSQL database crm (2.00 KiB)', Selection::database($dump)->describe());
        self::assertSame('PostgreSQL database crm (2.00 KiB), imported into the new database crm_new', Selection::database($dump, 'crm_new')->describe());
        self::assertSame('/etc/hosts to standard output', Selection::stdout('/etc/hosts')->describe());

        self::assertSame(['/srv/dumps/pgsql/crm.sql'], Selection::database($dump)->includes);
        self::assertTrue(Selection::database($dump)->needsTarget());
        self::assertFalse(Selection::database($dump, 'x')->needsTarget());
        self::assertTrue(Selection::database($dump, 'x')->imports());
        self::assertFalse(Selection::database($dump)->imports());
        self::assertFalse(Selection::stdout('/etc/hosts')->needsTarget());
        self::assertTrue(Selection::everything()->needsTarget());
    }

    public function testSelectedPathsFollowResticMatching(): void
    {
        self::assertTrue(SizeEstimator::selected('/srv/www/site/index.php', [], []));
        self::assertTrue(SizeEstimator::selected('/srv/www/site/index.php', ['/srv/www/site'], []), 'an included folder includes everything below it');
        self::assertTrue(SizeEstimator::selected('/srv/www/site/index.php', ['/srv/www/site/index.php'], []));
        self::assertFalse(SizeEstimator::selected('/srv/www/site/css/app.css', ['/srv/www/site/index.php'], []));
        self::assertFalse(SizeEstimator::selected('/srv/www/sitemap.xml', ['/srv/www/site'], []), 'a prefix of a name is not a parent');
        self::assertTrue(SizeEstimator::selected('/srv/www/a/b.php', ['*.php'], []));
        self::assertFalse(SizeEstimator::selected('/srv/www/site/debug.log', ['/srv/www'], ['*.log']));
    }

    public function testEstimate(): void
    {
        $runner = new FakeProcessRunner();
        $fake = (new FakeRestic())->withRepository()->install($runner);
        $restic = new Restic(new ResticSettings('/r', '/p'), '/usr/bin/restic', $runner);
        $snapshot = new Snapshot('6118b3c5', new DateTimeImmutable(), 'web1.example.com', ['/srv/dumps', '/srv/www']);
        $plan = static fn (Selection $s): RestorePlan => new RestorePlan($snapshot, $s, '/t', OwnerMode::Preserve);

        self::assertSame(82, SizeEstimator::estimate($restic, $plan(Selection::everything())), 'restic stats --mode restore-size');
        self::assertSame(['stats', '--json', '--mode', 'restore-size', '--', '6118b3c5'], $fake->calls[0]);
        self::assertSame(22 + 7, SizeEstimator::estimate($restic, $plan(Selection::paths(['/srv/www/site']))), 'index.php and css/app.css');
        self::assertSame(['ls', '--json', '--recursive', '--', '6118b3c5'], $fake->calls[1]);
        self::assertSame(82 - 25 - 25, SizeEstimator::estimate($restic, $plan(Selection::everything(['*.sql']))), 'both dumps left out');
        self::assertSame(25, SizeEstimator::estimate($restic, $plan(Selection::database(new DumpFile('mysql', 'shop', '/srv/dumps/mysql/shop.sql', 25)))));
        self::assertCount(3, $fake->calls, 'the dump size comes from the listing already made');
    }
}
