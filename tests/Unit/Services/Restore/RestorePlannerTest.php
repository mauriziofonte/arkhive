<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Restore;

use DateTimeImmutable;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Config\ResticSettings;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restore\OwnerMode;
use Mfonte\Arkhive\Services\Restore\RestoreOptions;
use Mfonte\Arkhive\Services\Restore\RestorePlan;
use Mfonte\Arkhive\Services\Restore\RestorePlanner;
use Mfonte\Arkhive\Services\Restore\SelectionKind;
use Mfonte\Arkhive\Services\Restore\Snapshots;
use PHPUnit\Framework\TestCase;
use Tests\Support\Configs;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakePrompter;
use Tests\Support\FakeRestic;
use Tests\Support\FixedClock;
use Tests\Support\TempDir;

/**
 * The wizard (spec §10.1) against scripted answers, and the unattended rules (spec §10.2).
 */
final class RestorePlannerTest extends TestCase
{
    use TempDir;

    private FakeRestic $fake;
    private Restic $restic;
    private Snapshots $snapshots;
    private string $cwd;

    protected function setUp(): void
    {
        $runner = new FakeProcessRunner();
        $this->fake = (new FakeRestic())->withRepository()->install($runner);
        $this->restic = new Restic(new ResticSettings('/srv/restic-repo', '/etc/arkhive/pw'), '/usr/bin/restic', $runner);
        $this->snapshots = new Snapshots($this->restic->snapshots());
        $this->cwd = $this->tempDir();
    }

    private function planner(?FakePrompter $prompter, int $uid = 0, ?Config $config = null, string $local = 'web1.example.com'): RestorePlanner
    {
        return new RestorePlanner($this->restic, $prompter, new FixedClock(new DateTimeImmutable('2026-10-12T18:00:00Z')), $this->cwd, $local, $uid, $config);
    }

    public function testWizardDefaultsOnlyRestoreTheLatestBackupEverythingToANewDirectory(): void
    {
        $prompter = new FakePrompter(['', '', '', '', '']);
        $planner = $this->planner($prompter);

        $plan = $planner->plan(new RestoreOptions(), $this->snapshots);

        self::assertSame('e82340a7', $plan->snapshot->shortId(), 'the newest backup of this host');
        self::assertSame(SelectionKind::Everything, $plan->selection->kind);
        self::assertSame("{$this->cwd}/arkhive-restore-web1.example.com-e82340a7-20261012-180000", $plan->target);
        self::assertSame(OwnerMode::Preserve, $plan->owner);
        self::assertFalse($plan->inPlace);
        self::assertFalse($plan->force);
        self::assertSame([
            'The repository holds snapshots of several hosts. Which host?',
            'Snapshot (number or id)',
            'What do you want to restore?',
            'Restore into (a new directory; never the original location)',
        ], $prompter->questions);
        self::assertStringContainsString('web1.example.com (3 snapshots)', $prompter->transcript);
        self::assertStringContainsString('web2.example.com (1 snapshot)', $prompter->transcript);
        self::assertMatchesRegularExpression('/^1  e82340a7  2026-10-12 14:17  3 h  web1\.example\.com  143\.05 MiB  arkhive  \/srv\/big$/m', $prompter->transcript);

        $planner->confirm($plan, 150000000, 10 * 1024 ** 3, false);
        self::assertSame('Start the restore?', end($prompter->questions));
        self::assertStringContainsString('Size      143.05 MiB (10.00 GiB free)', $prompter->transcript);
        self::assertStringContainsString('Check     every restored file is verified (restic restore --verify)', $prompter->transcript);
    }

    public function testWizardSkipsTheSafetySnapshotForTheDefault(): void
    {
        $prompter = new FakePrompter(['h:web2.example.com', '', '', '']);
        $plan = $this->planner($prompter)->plan(new RestoreOptions(), $this->snapshots);

        self::assertSame('fa06bee8', $plan->snapshot->shortId(), 'the only snapshot of web2, picked by id-less default');
        self::assertStringContainsString('Snapshot (number or id)', implode("\n", $prompter->questions));
    }

    public function testWizardPicksBySnapshotNumberOrIdAndRetriesBadAnswers(): void
    {
        $prompter = new FakePrompter(['3', '9', 'dddd', '4231', '', '']);
        $plan = $this->planner($prompter)->plan(new RestoreOptions(), $this->snapshots);

        self::assertSame('4231c203', $plan->snapshot->shortId());
        self::assertStringContainsString('There is no snapshot dddd in the repository', $prompter->transcript);
        self::assertStringContainsString("--snapshot must be a snapshot id or 'latest', not '9'", $prompter->transcript);
    }

    public function testWizardDatabaseDumpWithImport(): void
    {
        $prompter = new FakePrompter(['', '2', '3', '2', 'bad name!', 'crm_restored']);
        $plan = $this->planner($prompter, 0, Configs::make(pgsql: Configs::pgsql()))->plan(new RestoreOptions(), $this->snapshots);

        self::assertSame('6118b3c5', $plan->snapshot->shortId());
        self::assertSame(SelectionKind::Database, $plan->selection->kind);
        self::assertSame('crm', $plan->selection->dump?->database);
        self::assertSame('crm_restored', $plan->selection->importAs);
        self::assertNull($plan->target, 'an import writes no directory');
        self::assertStringContainsString("'bad name!' is not a database name", $prompter->transcript);
    }

    public function testWizardDatabaseDumpWithoutAConnectionRestoresTheFile(): void
    {
        $prompter = new FakePrompter(['', '2', '3', '1', '/tmp/restored-dump']);
        $plan = $this->planner($prompter)->plan(new RestoreOptions(), $this->snapshots);

        self::assertSame('shop', $plan->selection->dump?->database);
        self::assertNull($plan->selection->importAs);
        self::assertSame('/tmp/restored-dump', $plan->target);
        self::assertStringContainsString('the dump file is restored to a directory', $prompter->transcript);
    }

    public function testWizardSingleFileBySearch(): void
    {
        $prompter = new FakePrompter(['', '2', '4', '', 'index', '2']);
        $plan = $this->planner($prompter)->plan(new RestoreOptions(), $this->snapshots);

        self::assertSame(SelectionKind::Stdout, $plan->selection->kind);
        self::assertSame('/srv/www/site/index.php', $plan->selection->file);
        self::assertNull($plan->target);
        $find = $this->fake->callsOf('find')[0];
        self::assertSame(['find', '--json', '--snapshot', '6118b3c5b2b1ffc9e12d6022dfcab6275e9621122cb2b8d5b66d11a67fdc5c59', '--', '*index*'], $find);
    }

    public function testWizardPickFilesAndFolders(): void
    {
        $prompter = new FakePrompter([
            '', '2', '2',
            '', // nothing selected yet
            '2', // open /srv/www
            '+2', // select site/
            '3', // open with space/
            '1', // select the file
            '..', '..',
            '/app', '1', // search, select the result
            '', // done
            '', // target
        ]);
        $this->fake->on['find'] = static fn (): array => [0, '[{"matches":[{"path":"/srv/www/site/css/app.css","type":"file","size":7,"permissions":"-rw-r--r--","mtime":"2026-10-12T14:14:34Z"}],"hits":1,"snapshot":"6118b3c5b2b1ffc9e12d6022dfcab6275e9621122cb2b8d5b66d11a67fdc5c59"}]', ''];
        $plan = $this->planner($prompter)->plan(new RestoreOptions(), $this->snapshots);

        self::assertSame(SelectionKind::Paths, $plan->selection->kind);
        self::assertSame(['/srv/www/site', '/srv/www/with space/naïve file.txt', '/srv/www/site/css/app.css'], $plan->selection->includes);
        self::assertStringContainsString('Nothing is selected yet.', $prompter->transcript);
        self::assertStringContainsString('[x]   2) site/', $prompter->transcript);
    }

    public function testWizardRefusesANonEmptyTargetUnlessTypedConfirmation(): void
    {
        mkdir("{$this->cwd}/full");
        touch("{$this->cwd}/full/file");

        $plan = $this->planner(new FakePrompter(['', '', '', 'full', 'overwrite']))->plan(new RestoreOptions(), $this->snapshots);
        self::assertTrue($plan->force);

        $this->expectException(RefusedException::class);
        $this->expectExceptionMessage("The restore target {$this->cwd}/full is not empty and the restore was not confirmed: nothing was restored");
        $this->planner(new FakePrompter(['', '', '', 'full', 'yes']))->plan(new RestoreOptions(), $this->snapshots);
    }

    public function testWizardCanBeCancelled(): void
    {
        $prompter = new FakePrompter(['', '', '', '', 'n']);
        $planner = $this->planner($prompter);
        $plan = $planner->plan(new RestoreOptions(), $this->snapshots);

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('Restore cancelled: nothing was restored');
        $planner->confirm($plan, 1, null, false);
    }

    public function testInteractiveWithFlagsOnlyAsksWhatIsMissing(): void
    {
        $prompter = new FakePrompter(['']);
        $planner = $this->planner($prompter);
        $plan = $planner->plan(new RestoreOptions(snapshot: '6118', includes: ['/srv/www/site']), $this->snapshots);

        self::assertSame(['Restore into (a new directory; never the original location)'], $prompter->questions);
        self::assertSame(['/srv/www/site'], $plan->selection->includes);

        $planner->confirm($plan, 1, null, true);
        self::assertCount(1, $prompter->questions, '--yes answers the confirmation');
        self::assertStringContainsString('Restore   /srv/www/site', $prompter->transcript);
    }

    public function testInteractiveInPlaceNeedsTheTypedWordWithoutForce(): void
    {
        $plan = $this->planner(new FakePrompter(['overwrite']))->plan(new RestoreOptions(snapshot: 'latest', host: 'web1.example.com', includes: ['/srv/big'], inPlace: true), $this->snapshots);
        self::assertTrue($plan->inPlace);
        self::assertTrue($plan->force);
        self::assertSame('/', $plan->target);

        $this->expectException(RefusedException::class);
        $this->expectExceptionMessage('In-place restore not confirmed: nothing was restored');
        $this->planner(new FakePrompter(['no']))->plan(new RestoreOptions(snapshot: 'latest', inPlace: true), $this->snapshots);
    }

    public function testUnattendedNeedsSnapshotTargetAndYes(): void
    {
        foreach ([
            [new RestoreOptions(), 'The snapshot to restore (an id, or \'latest\') is required in unattended mode: pass --snapshot'],
            [new RestoreOptions(snapshot: 'latest'), 'The directory to restore into (new or empty) is required in unattended mode: pass --target'],
        ] as [$options, $message]) {
            try {
                $this->planner(null)->plan($options, $this->snapshots);
                self::fail('expected a usage error');
            } catch (UsageException $e) {
                self::assertSame($message, $e->getMessage());
                self::assertSame(2, $e->exitCode());
            }
        }

        $plan = $this->planner(null)->plan(new RestoreOptions(snapshot: 'latest', target: 'out'), $this->snapshots);
        self::assertSame("{$this->cwd}/out", $plan->target);
        try {
            $this->planner(null)->confirm($plan, 1, null, false);
            self::fail('expected a usage error');
        } catch (UsageException $e) {
            self::assertSame('The confirmation of the restore is required in unattended mode: pass --yes', $e->getMessage());
        }
        $this->planner(null)->confirm($plan, 1, null, true);
    }

    public function testUnattendedStdoutNeedsNoTargetNorYes(): void
    {
        $planner = $this->planner(null);
        $plan = $planner->plan(new RestoreOptions(snapshot: '6118', toStdout: '/srv/www/site/index.php'), $this->snapshots);

        self::assertNull($plan->target);
        $planner->confirm($plan, 22, null, false);
        $this->addToAssertionCount(1);
    }

    public function testUnattendedInPlaceWithoutForceIsRefused(): void
    {
        try {
            $this->planner(null)->plan(new RestoreOptions(snapshot: 'latest', inPlace: true), $this->snapshots);
            self::fail('expected a refusal');
        } catch (RefusedException $e) {
            self::assertSame(4, $e->exitCode());
            self::assertSame('An in-place restore overwrites the original files and needs --force', $e->getMessage());
        }

        $plan = $this->planner(null)->plan(new RestoreOptions(snapshot: 'latest', inPlace: true, force: true), $this->snapshots);
        self::assertTrue($plan->inPlace);
    }

    public function testUnattendedDatabaseAndOwner(): void
    {
        $plan = $this->planner(null, 1000)->plan(new RestoreOptions(snapshot: '6118', db: 'crm', importAs: 'crm_copy'), $this->snapshots);
        self::assertSame('/srv/dumps/pgsql/crm.sql', $plan->selection->dump?->path);
        self::assertSame(OwnerMode::Current, $plan->owner, 'not root: current by default');

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("--import-as 'crm copy' is not a database name ArkHive creates");
        $this->planner(null)->plan(new RestoreOptions(snapshot: '6118', db: 'crm', importAs: 'crm copy'), $this->snapshots);
    }

    public function testOwnerPreserveAsNonRootIsAUsageError(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('--owner preserve needs root');
        $this->planner(null, 1000)->plan(new RestoreOptions(snapshot: 'latest', target: 'x', owner: 'preserve'), $this->snapshots);
    }

    public function testConfirmSummaryForImportsAndInPlace(): void
    {
        $prompter = new FakePrompter(['', '']);
        $planner = $this->planner($prompter);
        $import = $planner->plan(new RestoreOptions(snapshot: '6118', db: 'shop', importAs: 'shop_new'), $this->snapshots);
        $planner->confirm($import, 25, null, false);
        self::assertStringContainsString('To        the new database shop_new', $prompter->transcript);
        self::assertStringContainsString('Check     the dump is imported with ON_ERROR_STOP', $prompter->transcript);

        $inPlace = new RestorePlan($import->snapshot, $import->selection, '/', OwnerMode::Preserve, true, true);
        $planner->confirm($inPlace, 25, null, false);
        self::assertStringContainsString('To        the original paths (in place; a safety snapshot is taken first)', $prompter->transcript);
    }
}
