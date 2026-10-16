<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\LockedException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Restic\ResticErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRestic;

/**
 * How failed restic runs are classified, table-driven over stderr captured from real restic
 * (tests/Fixtures/restic): a repository whose pack files were deleted on the server, a lock held
 * by another process, restic stopped by a signal from outside ArkHive.
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

    /**
     * @return iterable<string, array{int, int|null, string, class-string, int, string, bool}>
     */
    public static function failures(): iterable
    {
        $locked = 'The restic repository is locked by PID 25983 on host.example.com by root (UID 0, GID 0), created at 2026-10-12 13:58:02';
        // exit, signal, stderr => exception, exit code, message, retried in the run
        yield 'killed by the OOM killer' => [137, 9, '', TemporaryException::class, ExitCode::TEMPORARY, 'restic backup failed: restic was killed by signal 9 (SIGKILL: out of memory?)', false];
        yield 'killed by SIGTERM before it could catch it' => [143, 15, '', TemporaryException::class, ExitCode::TEMPORARY, 'restic backup failed: restic was killed by signal 15 (SIGTERM)', false];
        yield 'stopped by SIGTERM, 0.17 (exit 1)' => [1, null, FakeRestic::fixture('terminated-0.17.stderr'), TemporaryException::class, ExitCode::TEMPORARY, 'restic backup failed: restic was stopped by signal 15 (SIGTERM) and cancelled the operation', false];
        yield 'stopped by SIGTERM, 0.19 (exit 130, JSON)' => [130, null, FakeRestic::fixture('terminated-0.19.stderr'), TemporaryException::class, ExitCode::TEMPORARY, 'restic backup failed: restic was stopped by signal 15 (SIGTERM) and cancelled the operation', false];
        yield 'stopped by SIGINT' => [130, null, "signal interrupt received, cleaning up\n", TemporaryException::class, ExitCode::TEMPORARY, 'restic was stopped by signal 2 (SIGINT)', false];
        yield 'locked, text' => [11, null, FakeRestic::fixture('locked-0.19.stderr'), LockedException::class, ExitCode::TEMPORARY, $locked, true];
        yield 'locked, JSON 0.18' => [11, null, FakeRestic::fixture('locked-json-0.18.stderr'), LockedException::class, ExitCode::TEMPORARY, $locked, true];
        yield 'locked, JSON 0.19' => [11, null, FakeRestic::fixture('locked-json-0.19.stderr'), LockedException::class, ExitCode::TEMPORARY, $locked, true];
        yield 'locked, before exit codes (0.16)' => [1, null, FakeRestic::fixture('locked-0.19.stderr'), LockedException::class, ExitCode::TEMPORARY, $locked, true];
        yield 'wrong password, JSON' => [12, null, '{"message_type":"exit_error","code":12,"message":"Fatal: wrong password or no key found"}' . "\n", PermissionException::class, ExitCode::NO_PERMISSION, 'Wrong password for the restic repository', false];
        yield 'missing packs, JSON 0.19' => [1, null, FakeRestic::fixture('forget-missing-0.19.stderr'), DataException::class, ExitCode::DATA_ERROR, 'restic backup failed: 3 pack file(s) listed in the index are missing', false];
        yield 'tree not found, JSON' => [1, null, '{"message_type":"exit_error","code":1,"message":"Fatal: tree 4b2a17c9 not found in repository"}' . "\n", DataException::class, ExitCode::DATA_ERROR, 'should hold is missing (pack files deleted or lost on the server): Fatal: tree 4b2a17c9 not found in repository', false];
        yield 'no cache directory (no HOME), 0.19' => [1, null, FakeRestic::fixture('no-cache-directory-0.19.stderr'), ConfigException::class, ExitCode::CONFIG, 'restic backup failed: restic has no cache directory (neither HOME nor XDG_CACHE_HOME is set', false];
        yield 'unknown failure, JSON repeated' => [1, null, str_repeat('{"message_type":"error","error":{"message":"read /srv/www/a: input/output error"},"during":"archival","item":"/srv/www/a"}' . "\n", 3) . '{"message_type":"exit_error","code":1,"message":"Fatal: unable to save snapshot: boom"}' . "\n", ArkhiveException::class, ExitCode::FAILURE, 'restic backup failed (restic exit 1): read /srv/www/a: input/output error | Fatal: unable to save snapshot: boom', false];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('failures')]
    public function testFailuresAreClassifiedFromWhatResticPrints(int $exit, ?int $signal, string $stderr, string $class, int $code, string $message, bool $transient): void
    {
        $e = ResticErrors::exception(new ProcessResult(new Command(['/usr/bin/restic', 'backup']), $exit, '', $stderr, 1.0, false, $signal), 'restic backup', self::settings());

        self::assertSame($class, $e::class);
        self::assertSame($code, $e->exitCode());
        self::assertStringContainsString($message, $e->getMessage());
        self::assertSame($transient, $e->transient());
        self::assertStringNotContainsString('"message_type"', $e->getMessage(), 'never raw JSON');
    }

    public function testASignalTellsWhoToSuspect(): void
    {
        $killed = ResticErrors::exception(new ProcessResult(new Command(['/usr/bin/restic', 'backup']), 137, '', '', 1.0, false, 9), 'restic backup', self::settings());
        self::assertStringStartsWith("ArkHive did not send it: the kernel's out-of-memory killer (look for \"Out of memory\" in journalctl -k or dmesg", (string) $killed->hint());

        $stopped = ResticErrors::exception(new ProcessResult(new Command(['/usr/bin/restic', 'backup']), 130, '', FakeRestic::fixture('terminated-0.19.stderr')), 'restic backup', self::settings());
        self::assertSame('ArkHive did not send it: an administrator, a service manager or a shutdown did. Run it again once the cause is gone', $stopped->hint());
    }

    public function testATimeoutIsNotASignalFromOutside(): void
    {
        $e = ResticErrors::exception(new ProcessResult(new Command(['/usr/bin/restic', 'backup'], timeout: 30.0), 143, '', '', 30.0, true), 'restic backup', self::settings());

        self::assertSame('restic backup timed out after 30s (restic)', $e->getMessage());
        self::assertTrue($e->transient());
    }
}
