<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Restic\ResticErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRestic;

/**
 * How failed restic runs are classified, table-driven over stderr captured from real restic
 * (tests/Fixtures/restic): a repository whose pack files were deleted on the server.
 */
final class ResticErrorsTest extends TestCase
{
    private const REPOSITORY = 'sftp:u123456-sub1@backup.example.com:/home/arkhive';

    private const RESTIC = "RESTIC_PASSWORD_FILE='/etc/arkhive/restic-password' restic -r 'sftp:u123456-sub1@backup.example.com:/home/arkhive'";

    private const MISSING_HINT = 'Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then run, in this order: restic repair index, then arkhive backup (uploads again what is still on this host), then restic repair snapshots --forget, then arkhive maintain (restic as: ' . self::RESTIC . '). ArkHive never repairs a repository automatically';

    private static function settings(): ResticSettings
    {
        return new ResticSettings(self::REPOSITORY, '/etc/arkhive/restic-password', null, 'restic', 'host.example.com');
    }

    private static function classify(int $exit, string $stderr, string $what = 'restic forget'): \Mfonte\Arkhive\Exceptions\ArkhiveException
    {
        return ResticErrors::exception(new ProcessResult(new Command(['/usr/bin/restic', 'forget']), $exit, '', $stderr), $what, self::settings());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function missingData(): iterable
    {
        yield 'prune 0.19' => ['forget-missing-0.19.stderr', 'Applying the retention policy (restic forget --prune)', 'Applying the retention policy (restic forget --prune) failed: 3 pack file(s) listed in the index are missing from the repository ' . self::REPOSITORY . ' (381d4654, ca8af500, a1d3d946)'];
        yield 'prune 0.18' => ['forget-missing-0.18.stderr', 'Applying the retention policy (restic forget --prune)', 'Applying the retention policy (restic forget --prune) failed: 2 pack file(s) listed in the index are missing from the repository ' . self::REPOSITORY . ' (94971f42, b2f99d3c)'];
        yield 'prune 0.17' => ['forget-missing-0.17.stderr', 'Applying the retention policy (restic forget --prune)', 'Applying the retention policy (restic forget --prune) failed: 3 pack file(s) listed in the index are missing from the repository ' . self::REPOSITORY . ' (3492b848, d4534473, 957cd477)'];
        yield 'stats of a snapshot whose tree is gone 0.19' => ['stats-missing-0.19.stderr', 'Measuring snapshot ce8dcb76', 'Measuring snapshot ce8dcb76 failed: data the repository ' . self::REPOSITORY . ' should hold is missing (pack files deleted or lost on the server): Load(<data/a1d3d9469a>, 332, 0) failed: open /srv/restic-repo/data/a1/a1d3d9469ac4a5d0b4cab5907701dbbb13e06f81021c0feb0d2eac4805140d57: no such file or directory'];
        yield 'restore with missing data 0.19' => ['restore-missing-0.19.stderr', 'restic restore', 'restic restore failed: data the repository ' . self::REPOSITORY . ' should hold is missing (pack files deleted or lost on the server): Load(<data/381d4654a9>, 14954566, 0) failed: open /srv/restic-repo/data/38/381d4654a98915f1e99861f0fc60381cff66d869fb07778655c9c1471495db09: no such file or directory'];
    }

    #[DataProvider('missingData')]
    public function testMissingDataIsADataErrorWithTheRepairOrder(string $fixture, string $what, string $message): void
    {
        $e = self::classify(1, FakeRestic::fixture($fixture), $what);

        self::assertInstanceOf(DataException::class, $e);
        self::assertSame(ExitCode::DATA_ERROR, $e->exitCode());
        self::assertSame($message, $e->getMessage());
        self::assertSame(self::MISSING_HINT, $e->hint());
        self::assertFalse($e->transient(), 'a damaged repository is never retried');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dataErrorLines(): iterable
    {
        yield 'tree not found' => ["Fatal: unable to load snapshot: tree 4b2a17c9 not found in repository\n", 'data the repository ' . self::REPOSITORY . ' should hold is missing (pack files deleted or lost on the server): Fatal: unable to load snapshot: tree 4b2a17c9 not found in repository'];
        yield 'blob not found' => ["blob 44de49eb9f869ab3 not found\n", 'should hold is missing (pack files deleted or lost on the server): blob 44de49eb9f869ab3 not found'];
        yield 'sftp file missing, JSON record' => ['{"message_type":"exit_error","code":1,"message":"ReadFull(<data/381d4654a9>): file does not exist"}' . "\n", 'should hold is missing (pack files deleted or lost on the server): ReadFull(<data/381d4654a9>): file does not exist'];
        yield 'ciphertext' => ["Fatal: failed to restore: pack 9c44581e: ciphertext verification failed\n", 'the repository ' . self::REPOSITORY . ' holds damaged data: Fatal: failed to restore: pack 9c44581e: ciphertext verification failed'];
        yield 'LoadRaw' => ["LoadRaw(<data/9c44581e68>): invalid data returned\n", 'holds damaged data: LoadRaw(<data/9c44581e68>): invalid data returned'];
    }

    #[DataProvider('dataErrorLines')]
    public function testDataErrorsAreRecognisedInTextAndJson(string $stderr, string $message): void
    {
        $e = self::classify(1, $stderr, 'X');

        self::assertSame(ExitCode::DATA_ERROR, $e->exitCode());
        self::assertStringContainsString($message, $e->getMessage());
        self::assertStringContainsString('077_troubleshooting.html', (string) $e->hint());
    }

    public function testDamagedButNotMissingDataPointsAtTheCheck(): void
    {
        $e = self::classify(1, "Fatal: failed to restore: ciphertext verification failed\n");

        self::assertSame('Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first, and follow it for the errors restic check reports (arkhive maintain runs it); back up again afterwards. ArkHive never repairs a repository automatically', $e->hint());
    }

    public function testANetworkErrorWhileReadingPacksIsNotDamage(): void
    {
        $e = self::classify(1, "ReadFull(<data/381d4654a9>): ssh command exited: connection lost\n");

        self::assertSame(ExitCode::UNAVAILABLE, $e->exitCode());
        self::assertTrue($e->transient());
    }
}
