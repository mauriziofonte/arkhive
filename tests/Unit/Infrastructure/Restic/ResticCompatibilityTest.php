<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\BackupRequest;
use Mfonte\Arkhive\Domain\Repository\RestoreRequest;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Restic\Restic;
use Mfonte\Arkhive\Infrastructure\Restic\ResticErrors;
use Mfonte\Arkhive\Infrastructure\Restic\ResticOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;

/**
 * The fallbacks for restic older than the current release, down to 0.11.0 (Debian 11), with output
 * captured from each version (docs/2.1-PLAN.md §3).
 */
final class ResticCompatibilityTest extends TestCase
{
    private FakeProcessRunner $runner;
    private FakeRestic $fake;
    private Restic $restic;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->fake = (new FakeRestic())->install($this->runner);
        $this->restic = new Restic(new ResticSettings('/srv/restic-repo', '/etc/arkhive/restic-password'), '/usr/bin/restic', $this->runner);
    }

    private function version(string $version): void
    {
        $this->fake->version = "restic {$version} compiled with go1.24.7 on linux/amd64\n";
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function inits(): iterable
    {
        yield '0.11 text' => ['0.11.0', 'init-0.11.txt', 'fc8be954da'];
        yield '0.12 text' => ['0.12.1', 'init-0.12.txt', ''];
        yield '0.14 text' => ['0.14.0', 'init-0.14.txt', ''];
        yield '0.19 json' => ['0.19.1', '', 'c24ce18e868af7d52372dbd546c3b318ec0919530a107f703bfecb2a1c7b7236'];
    }

    #[DataProvider('inits')]
    public function testInitReadsTheRepositoryIdFromTextOrJson(string $version, string $fixture, string $id): void
    {
        $this->version($version);
        $output = $fixture === ''
            ? "{\"message_type\":\"initialized\",\"id\":\"{$id}\",\"repository\":\"/srv/restic-repo\"}\n"
            : FakeRestic::fixture($fixture);
        $this->fake->on['init'] = static fn (): array => [0, $output, ''];

        $created = $this->restic->init();

        if ($id === '') {
            self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', (string) $created);
        } else {
            self::assertSame($id, $created);
        }
    }

    public function testBackupOn011LeavesOutCompressionAndResolvesTheShortSnapshotId(): void
    {
        $this->version('0.11.0');
        $this->fake->backup = [0, FakeRestic::fixture('backup-0.11.jsonl'), ''];
        $this->fake->on['snapshots'] = static fn (): array => [0, FakeRestic::fixture('snapshots-0.11.json'), ''];

        $outcome = $this->restic->backup(new BackupRequest(['/srv/www'], 'web1.example.com', ['arkhive'], 'restic backup', compression: 'auto'));

        self::assertSame(['backup', '--json', '--tag', 'arkhive', '--host', 'web1.example.com', '--', '/srv/www'], $this->fake->backups[0]);
        self::assertSame('f72e09dcd65cdd4e4cf99fa7c2d3a5b42abe776f33e524bdf401cde1880dae36', $outcome->snapshotId());
        self::assertSame(['snapshots', '--json', '--host', 'web1.example.com'], $this->fake->callsOf('snapshots')[0]);
        self::assertSame(3, $outcome->summary?->filesNew);
    }

    public function testBackupOn014CompressesAndKeepsAShortIdNoSnapshotMatches(): void
    {
        $this->version('0.14.0');
        $this->fake->backup = [0, FakeRestic::fixture('backup-0.14.jsonl'), ''];
        $this->fake->on['snapshots'] = static fn (): array => [0, FakeRestic::fixture('snapshots-0.11.json'), ''];

        $outcome = $this->restic->backup(new BackupRequest(['/srv/www'], 'web1.example.com', ['arkhive'], 'restic backup', compression: 'max'));

        self::assertContains('--compression', $this->fake->backups[0]);
        self::assertSame('bed594d7', $outcome->snapshotId());
    }

    public function testBackupOn019HasTheFullIdAndAsksNothingMore(): void
    {
        $outcome = $this->restic->backup(new BackupRequest(['/srv/www'], 'web1.example.com', ['arkhive'], 'restic backup'));

        self::assertSame(64, strlen((string) $outcome->snapshotId()));
        self::assertSame([], $this->fake->callsOf('snapshots'));
    }

    public function testRestoreOn014HasNoSparseNorJsonSoTheTotalsComeFromTheSnapshot(): void
    {
        $this->version('0.14.0');
        $this->fake->on['restore'] = static fn (): array => [0, FakeRestic::fixture('restore-0.14.txt'), ''];
        $this->fake->on['ls'] = static fn (): array => [0, FakeRestic::fixture('ls-recursive-0.14.jsonl'), ''];
        $progress = [];

        $summary = $this->restic->restore(
            new RestoreRequest('bed594d725edb0df7dd8d5e978ce89db96d48f7f5ab622552dc048140cd8686b', '/srv/restore', 'restic restore', ['/srv/www/site'], ['*.css']),
            static function () use (&$progress): void {
                $progress[] = true;
            },
        );

        self::assertSame(['restore', '--json', '--verify', '--target', '/srv/restore', '--include', '/srv/www/site', '--exclude', '*.css', '--', 'bed594d725edb0df7dd8d5e978ce89db96d48f7f5ab622552dc048140cd8686b'], $this->fake->callsOf('restore')[0]);
        self::assertSame(['ls', '--json', '--recursive', '--', 'bed594d725edb0df7dd8d5e978ce89db96d48f7f5ab622552dc048140cd8686b'], $this->fake->callsOf('ls')[0]);
        self::assertNotNull($summary);
        self::assertSame(3, $summary->filesRestored, 'site/, index.html and logo.bin; style.css is excluded');
        self::assertSame(15 + 50000, $summary->bytesRestored);
        self::assertSame([], $progress);
    }

    public function testRestoreOn016UsesResticsOwnSummary(): void
    {
        $this->version('0.16.4');
        $this->fake->withRepository();

        $summary = $this->restic->restore(new RestoreRequest('6118b3c5', sys_get_temp_dir() . '/arkhive-compat-' . bin2hex(random_bytes(4)), 'restic restore'));

        self::assertContains('--sparse', $this->fake->callsOf('restore')[0]);
        self::assertSame([], $this->fake->callsOf('ls'));
        self::assertGreaterThan(0, $summary?->filesRestored);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function damagedChecks(): iterable
    {
        yield '0.11 short pack id' => ['check-damaged-0.11.txt', ['d69ad6d4']];
        yield '0.12 short pack id' => ['check-damaged-0.12.txt', ['9918f67d']];
        yield '0.14 full pack id' => ['check-damaged-0.14.txt', ['5161dbc96fa631a0077976bec96a4f3fdc1f31170e5eed9e78ae7467bf8a8558']];
    }

    /**
     * @param list<string> $broken
     */
    #[DataProvider('damagedChecks')]
    public function testADamagedRepositoryInTheTextOutput(string $fixture, array $broken): void
    {
        $check = ResticOutput::checkResult(1, FakeRestic::fixture($fixture), '');

        self::assertFalse($check->ok);
        self::assertNotSame([], $check->errors);
        self::assertSame($broken, $check->brokenPacks);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function healthyChecks(): iterable
    {
        yield '0.11' => ['check-ok-0.11.txt'];
        yield '0.12' => ['check-ok-0.12.txt'];
        yield '0.14' => ['check-ok-0.14.txt'];
    }

    #[DataProvider('healthyChecks')]
    public function testAHealthyRepositoryInTheTextOutput(string $fixture): void
    {
        $check = ResticOutput::checkResult(0, FakeRestic::fixture($fixture), '');

        self::assertTrue($check->ok);
        self::assertSame([], $check->errors);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function errors(): iterable
    {
        yield 'no repository' => ['no-repository-0.11.stderr', ExitCode::UNAVAILABLE];
        yield 'wrong password' => ['wrong-password-0.11.stderr', ExitCode::NO_PERMISSION];
        yield 'format 2 repository' => ['cat-config-v2-0.12.txt', ExitCode::UNAVAILABLE];
    }

    #[DataProvider('errors')]
    public function testErrorsOfOldResticAreRecognisedFromStderr(string $fixture, int $exit): void
    {
        $result = new ProcessResult(new Command(['/usr/bin/restic', 'cat', 'config']), 1, '', FakeRestic::fixture($fixture));

        self::assertSame($exit, ResticErrors::exception($result, 'Opening the restic repository', new ResticSettings('/srv/restic-repo', '/etc/arkhive/restic-password'))->exitCode());
    }
}
