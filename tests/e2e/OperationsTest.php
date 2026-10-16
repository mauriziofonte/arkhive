<?php

declare(strict_types=1);

namespace Tests\e2e;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\ExitCode;

/**
 * spec §13.2 steps 2 and 7, §13.5 drill and §13.6 corrupted pack: init, maintain with the 7/4/6
 * policy on back-dated snapshots, status, drill (pass, then fail on a corrupted pack) and doctor.
 */
final class OperationsTest extends E2eTestCase
{
    use DatabaseClients;

    private const HOST = 'e2e.example.com';

    private string $dir;
    private string $tree;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->tree = "{$this->dir}/site";
        mkdir("{$this->dir}/locks");
        mkdir("{$this->dir}/tmp", 0700);
        mkdir("{$this->tree}/data", 0755, true);
        file_put_contents("{$this->tree}/index.php", "<?php echo 'site';\n");
        file_put_contents("{$this->tree}/data/large.bin", random_bytes(2 * 1024 * 1024));
        for ($i = 0; $i < 20; $i++) {
            file_put_contents("{$this->tree}/data/file-{$i}.txt", str_repeat("line {$i}\n", 100 + $i));
        }
    }

    /**
     * @return array<string, string>
     */
    private function runEnv(): array
    {
        return ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'TMPDIR' => "{$this->dir}/tmp", 'STATE_DIRECTORY' => "{$this->dir}/state"];
    }

    /**
     * @param array<string, string> $values
     */
    private function config(string $repository, ?string $sftpCommand, array $values = []): string
    {
        return $this->writeConfig("{$this->dir}/config", [
            'BACKUP_PATHS' => $this->tree,
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            'RESTIC_HOST' => self::HOST,
            ...($sftpCommand === null ? [] : ['RESTIC_SFTP_COMMAND' => $sftpCommand]),
            ...$values,
        ]);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function json(array $args, int $exit, string $config): array
    {
        $result = self::arkhive([...$args, '--json', '--config', $config], $this->runEnv());
        self::assertSame($exit, $result->exitCode, $result->output());
        $data = json_decode($result->stdout, true);
        self::assertIsArray($data, $result->output());

        return $data;
    }

    private function backup(string $config): void
    {
        $result = self::arkhive(['backup', '--config', $config], $this->runEnv());
        self::assertSame(0, $result->exitCode, $result->output());
    }

    public function testInitGeneratesThePasswordAndIsIdempotent(): void
    {
        $repository = "{$this->dir}/repo";
        $passwordFile = "{$this->dir}/secret/password";
        mkdir(dirname($passwordFile), 0700);
        $config = $this->writeConfig("{$this->dir}/config", ['BACKUP_PATHS' => $this->tree, 'RESTIC_REPOSITORY' => $repository, 'RESTIC_PASSWORD_FILE' => $passwordFile]);

        $created = $this->json(['init'], 0, $config);
        self::assertTrue($created['created']);
        self::assertTrue($created['password_generated']);
        clearstatcache();
        self::assertSame(0600, fileperms($passwordFile) & 0777);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}\n$/', (string) file_get_contents($passwordFile));
        $cat = self::exec([self::env('E2E_RESTIC'), 'cat', 'config'], ['RESTIC_REPOSITORY' => $repository, 'RESTIC_PASSWORD_FILE' => $passwordFile]);
        self::assertSame(0, $cat->exitCode, 'restic opens the repository with the generated password');

        $again = self::arkhive(['init', '--config', $config], $this->runEnv());
        self::assertSame(0, $again->exitCode, $again->output());
        self::assertStringContainsString("success The repository {$repository} already exists and the password opens it: nothing to do", $again->stderr);

        $other = "{$this->dir}/secret/other";
        file_put_contents($other, "another-password-placeholder\n");
        chmod($other, 0600);
        $wrong = self::arkhive(['init', '--config', $this->writeConfig("{$this->dir}/config", ['BACKUP_PATHS' => $this->tree, 'RESTIC_REPOSITORY' => $repository, 'RESTIC_PASSWORD_FILE' => $other])], $this->runEnv());
        self::assertSame(ExitCode::NO_PERMISSION, $wrong->exitCode, $wrong->output());
        self::assertStringContainsString("error Wrong password for the restic repository {$repository}", $wrong->stderr);
    }

    /**
     * restic's policy rules: walking newest to oldest, a snapshot is kept when its day (week, month)
     * differs from the last one a rule kept, while that rule has keeps left.
     *
     * @param list<array{string, DateTimeImmutable}> $snapshots newest first
     *
     * @return list<string> kept ids
     */
    private static function expectedKept(array $snapshots, int $daily, int $weekly, int $monthly): array
    {
        $rules = [['Y-m-d', $daily, null], ['o-W', $weekly, null], ['Y-m', $monthly, null]];
        $kept = [];
        foreach ($snapshots as [$id, $time]) {
            $keep = false;
            foreach ($rules as $i => [$format, $left, $last]) {
                if ($left <= 0) {
                    continue;
                }
                $bucket = $time->format($format);
                if ($bucket !== $last) {
                    $keep = true;
                    $rules[$i][1] = $left - 1;
                    $rules[$i][2] = $bucket;
                }
            }
            if ($keep) {
                $kept[] = $id;
            }
        }

        return $kept;
    }

    /**
     * @return list<array{string, DateTimeImmutable}> [id, time] of the host's ArkHive snapshots, newest first
     */
    private static function snapshots(string $repository, ?string $sftpCommand): array
    {
        $result = self::restic(['snapshots', '--json', '--host', self::HOST, '--tag', 'arkhive'], $repository, $sftpCommand);
        self::assertSame(0, $result->exitCode, $result->stderr);
        $list = [];
        foreach ((array) json_decode($result->stdout, true) as $snapshot) {
            self::assertIsArray($snapshot);
            $list[] = [(string) $snapshot['id'], new DateTimeImmutable(substr((string) $snapshot['time'], 0, 19) . 'Z')];
        }
        usort($list, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return $list;
    }

    public function testMaintainKeepsExactlyThe746SetAndTouchesNothingElse(): void
    {
        [$repository, $sftpCommand] = self::sftpRepository();
        $this->arkhiveInit($this->dir, $repository, $sftpCommand);
        $now = new DateTimeImmutable('today 12:00', new DateTimeZone('UTC'));
        $days = [...range(0, 9), ...range(10, 60, 2), ...range(70, 250, 10)];
        foreach ($days as $day) {
            $time = $now->modify("-{$day} days")->format('Y-m-d H:i:s');
            $backup = self::restic(['backup', '--quiet', '--tag', 'arkhive', '--host', self::HOST, '--time', $time, $this->tree], $repository, $sftpCommand, ['TZ' => 'UTC']);
            self::assertSame(0, $backup->exitCode, $backup->stderr);
        }
        $old = $now->modify('-300 days')->format('Y-m-d H:i:s');
        foreach ([['--tag', 'pre-restore', '--host', self::HOST], ['--tag', 'arkhive', '--host', 'other.example.com']] as $extra) {
            self::assertSame(0, self::restic(['backup', '--quiet', ...$extra, '--time', $old, $this->tree], $repository, $sftpCommand, ['TZ' => 'UTC'])->exitCode);
        }
        $before = self::snapshots($repository, $sftpCommand);
        self::assertCount(count($days), $before);
        $expected = self::expectedKept($before, 7, 4, 6);

        $report = $this->json(['maintain'], 0, $this->config($repository, $sftpCommand));

        $after = array_map(static fn (array $s): string => $s[0], self::snapshots($repository, $sftpCommand));
        self::assertEqualsCanonicalizing($expected, $after, 'kept exactly the 7 daily / 4 weekly / 6 monthly set');
        self::assertSame(count($expected), $report['snapshots_kept']);
        self::assertCount(count($days) - count($expected), (array) $report['snapshots_removed']);
        self::assertTrue($report['healthy']);
        self::assertSame(['ok' => true, 'errors' => []], $report['check']);
        self::assertSame(['subset' => '5%', 'ok' => true, 'errors' => []], $report['read_data']);
        self::assertGreaterThan(0, $report['raw_size']);

        $others = self::restic(['snapshots', '--json'], $repository, $sftpCommand);
        $all = (array) json_decode($others->stdout, true);
        self::assertCount(count($expected) + 2, $all, 'the safety snapshot and the other host are untouched');
    }

    public function testStatusReportsTheLatestSnapshot(): void
    {
        [$repository, $sftpCommand] = self::sftpRepository();
        $this->arkhiveInit($this->dir, $repository, $sftpCommand);
        $config = $this->config($repository, $sftpCommand);

        $empty = self::arkhive(['status', '--config', $config, '--max-age', '26'], $this->runEnv());
        self::assertSame(1, $empty->exitCode, $empty->output());
        self::assertStringContainsString('error No snapshot of e2e.example.com in the repository', $empty->stderr);

        $this->backup($config);
        $this->backup($config);
        $status = $this->json(['status', '--max-age', '1'], 0, $config);
        self::assertSame(self::HOST, $status['host']);
        self::assertSame(2, $status['snapshots']);
        self::assertIsArray($status['latest']);
        self::assertLessThan(0.1, $status['latest']['age_hours']);
        self::assertGreaterThan(2 * 1024 * 1024, $status['raw_size']);
        self::assertSame(0, $status['runs']['backup']['last']['exit_code'], 'the last backup is recorded in STATE_DIRECTORY');
        self::assertSame($status['latest']['id'], $status['runs']['backup']['last']['snapshot_id']);
        self::assertNull($status['runs']['drill']['last']);
        self::assertSame(0600, fileperms("{$this->dir}/state/backup.json") & 0777);

        $human = self::arkhive(['status', '--config', $config], $this->runEnv());
        self::assertSame(0, $human->exitCode);
        self::assertMatchesRegularExpression('/^Latest snapshot: [0-9a-f]{8} at .+ \(\d+ min ago\)$/m', $human->stdout);
        self::assertMatchesRegularExpression('/^Last backup:     .+, OK, snapshot [0-9a-f]{8}$/m', $human->stdout);
        self::assertStringStartsWith("Repository:      {$repository}\n", $human->stdout, 'stdout carries only the result, the log goes to stderr');
    }

    public function testDrillPassesOnAHealthyRepositoryAndImportsADump(): void
    {
        [$repository, $sftpCommand] = self::sftpRepository();
        $this->arkhiveInit($this->dir, $repository, $sftpCommand);
        $this->backup($this->config($repository, $sftpCommand, $this->databaseConfig("{$this->dir}/dumps")));

        foreach (['mysql' => ['WITH_PGSQL' => 'false'], 'pgsql' => ['WITH_MYSQL' => 'false']] as $engine => $only) {
            $config = $this->config($repository, $sftpCommand, [...$this->databaseConfig("{$this->dir}/dumps"), ...$only]);
            $report = $this->json(['drill', '--sample', '10', '--db'], 0, $config);

            self::assertTrue($report['passed'], (string) json_encode($report));
            self::assertSame(10, $report['files_tested']);
            self::assertIsArray($report['database']);
            self::assertSame($engine, $report['database']['engine']);
            self::assertTrue($report['database']['ok']);
            self::assertMatchesRegularExpression('/^arkhive_drill_[0-9a-f]{8}$/', $report['database']['imported_as']);
        }
        self::assertSame('0', $this->mysql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'arkhive\\_drill\\_%'"), 'the throw-away databases are dropped');
        self::assertSame('0', $this->psql("SELECT COUNT(*) FROM pg_database WHERE datname LIKE 'arkhive\\_drill\\_%'"));
        self::assertSame([], glob("{$this->dir}/tmp/*") ?: [], 'nothing left in the temporary directory');
    }

    private static function corruptLargestPack(string $repository): string
    {
        $packs = glob("{$repository}/data/*/*") ?: [];
        self::assertNotSame([], $packs);
        usort($packs, static fn (string $a, string $b): int => filesize($b) <=> filesize($a));
        chmod($packs[0], 0600);
        $handle = fopen($packs[0], 'r+b');
        self::assertIsResource($handle);
        fseek($handle, 4096);
        fwrite($handle, str_repeat("\0", 64));
        fclose($handle);

        return basename($packs[0]);
    }

    public function testACorruptedPackFailsTheDrillAndMaintain(): void
    {
        $repository = "{$this->dir}/repo";
        $this->arkhiveInit($this->dir, $repository, null);
        $config = $this->config($repository, null, ['RESTIC_CHECK_SUBSET' => '100%']);
        $this->backup($config);
        self::assertTrue($this->json(['drill'], 0, $config)['passed']);

        $pack = self::corruptLargestPack($repository);

        $drill = $this->json(['drill'], ExitCode::DATA_ERROR, $config);
        self::assertFalse($drill['passed']);
        self::assertNotSame([], $drill['failures']);
        self::assertSame([], glob("{$this->dir}/tmp/*") ?: []);

        $maintain = $this->json(['maintain'], ExitCode::DATA_ERROR, $config);
        self::assertFalse($maintain['healthy']);
        self::assertContains($pack, $maintain['broken_packs']);
        $log = self::arkhive(['maintain', '--config', $config], $this->runEnv());
        self::assertSame(ExitCode::DATA_ERROR, $log->exitCode);
        self::assertStringContainsString("error The repository check found errors: pack {$pack}", $log->stderr);
        self::assertStringContainsString("restic repair packs {$pack}", $log->stderr);
        foreach (glob("{$this->dir}/locks/*") ?: [] as $lock) {
            self::assertSame('', file_get_contents($lock), 'the lock is released');
        }
    }

    /**
     * Pack files deleted on the server: the backup cannot see it (restic trusts its index), maintain
     * and restore report it as damage (65) with the repair order, and later backups carry a warning.
     */
    public function testPackFilesDeletedOnTheServerAreDamageNotSuccess(): void
    {
        $repository = "{$this->dir}/repo";
        $this->arkhiveInit($this->dir, $repository, null);
        $config = $this->config($repository, null, ['RESTIC_CHECK_SUBSET' => '100%']);
        $this->backup($config);
        file_put_contents("{$this->tree}/data/second.txt", "second run\n");
        $this->backup($config);

        $packs = glob("{$repository}/data/*/*") ?: [];
        self::assertNotSame([], $packs);
        foreach ($packs as $pack) {
            unlink($pack);
        }
        file_put_contents("{$this->tree}/data/third.txt", "third run\n");
        $this->backup($config);

        $before = self::snapshots($repository, null);
        self::assertCount(3, $before);
        $maintain = self::arkhive(['maintain', '--config', $config], $this->runEnv());
        self::assertSame(ExitCode::DATA_ERROR, $maintain->exitCode, $maintain->output());
        self::assertStringContainsString('warning The retention policy was not applied: the repository check found errors, so no snapshot was removed', $maintain->stderr);
        self::assertMatchesRegularExpression('/error The repository check found errors: .*pack file\(s\) listed in the index are missing/', $maintain->stderr);
        self::assertSame($before, self::snapshots($repository, null), 'a damaged repository keeps every snapshot (3 same-day backups, 1 daily kept otherwise)');
        self::assertStringContainsString('restic repair index, then arkhive backup', $maintain->stderr);
        self::assertStringNotContainsString('"message_type"', $maintain->stderr, 'no raw restic JSON');
        $report = $this->json(['maintain'], ExitCode::DATA_ERROR, $config);
        self::assertFalse($report['healthy']);
        self::assertNotSame([], $report['missing_packs']);

        $backup = self::arkhive(['backup', '--config', $config], $this->runEnv());
        self::assertSame(ExitCode::WARNING, $backup->exitCode, $backup->output());
        self::assertStringContainsString('found the repository damaged and no maintain has passed since', $backup->stderr);

        $restore = self::arkhive(['restore', '--config', $config, '--snapshot', 'latest', '--target', "{$this->dir}/restored", '--yes'], $this->runEnv());
        self::assertSame(ExitCode::DATA_ERROR, $restore->exitCode, $restore->output());
        self::assertStringContainsString('restic repair index', $restore->stderr);
        self::assertDirectoryDoesNotExist("{$this->dir}/restored");
    }

    public function testADeletedLastBackupIsCaughtByStatusAndDrill(): void
    {
        $repository = "{$this->dir}/repo";
        $this->arkhiveInit($this->dir, $repository, null);
        $config = $this->config($repository, null);
        $this->backup($config);
        file_put_contents("{$this->tree}/data/second.txt", "second run\n");
        $this->backup($config);

        $state = json_decode((string) file_get_contents("{$this->dir}/state/" . substr(hash('sha256', $repository), 0, 16) . '/backup.json'), true);
        self::assertIsArray($state, 'run state kept per repository');
        $last = (string) $state['last_success']['snapshot_id'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $last, 'the full snapshot id');
        self::assertSame('ok', $this->json(['status'], 0, $config)['continuity']['status']);

        // what a rollback of the storage to before the second backup leaves behind
        unlink("{$repository}/snapshots/{$last}");

        $status = $this->json(['status'], ExitCode::DATA_ERROR, $config);
        self::assertSame('missing', $status['continuity']['status']);
        self::assertTrue($status['continuity']['newest_older']);
        $drill = self::arkhive(['drill', '--config', $config], $this->runEnv());
        self::assertSame(ExitCode::DATA_ERROR, $drill->exitCode, $drill->output());
        self::assertStringContainsString("Snapshot {$last} recorded as the last successful backup at", $drill->stderr);
        self::assertStringContainsString('is missing from the repository (deleted or rolled back)', $drill->stderr);

        $backup = self::arkhive(['backup', '--config', $config], $this->runEnv());
        self::assertSame(ExitCode::WARNING, $backup->exitCode, 'the failed drill stands until a maintain passes: ' . $backup->output());
        self::assertSame('ok', $this->json(['status'], 0, $config)['continuity']['status'], 'a new backup starts a new series');
    }

    public function testALockOfAResticKilledOnThisHostIsNamedAndRemovedOnlyWithUnlockStale(): void
    {
        $repository = "{$this->dir}/repo";
        $this->arkhiveInit($this->dir, $repository, null);
        $this->backup($this->config($repository, null));

        // a backup that holds its lock while it waits for stdin, then dies by SIGKILL
        $process = proc_open([self::env('E2E_RESTIC'), 'backup', '--stdin', '--stdin-filename', 'pending.sql', '--host', self::HOST], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            'PATH' => self::path(),
            'HOME' => $this->dir,
        ]);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        for ($i = 0; $i < 300 && (glob("{$repository}/locks/*") ?: []) === []; $i++) {
            usleep(100000);
        }
        self::assertCount(1, glob("{$repository}/locks/*") ?: [], 'restic took its lock');
        posix_kill($pid, SIGKILL);
        fclose($pipes[0]);
        proc_close($process);
        self::assertCount(1, glob("{$repository}/locks/*") ?: [], 'a killed restic leaves its lock behind');

        $started = microtime(true);
        $maintain = self::arkhive(['maintain', '--config', $this->config($repository, null, ['LOCK_WAIT' => '60'])], $this->runEnv());
        self::assertSame(ExitCode::TEMPORARY, $maintain->exitCode, $maintain->output());
        self::assertStringContainsString("a stale lock from a dead process on this host (PID {$pid} no longer exists)", $maintain->stderr);
        self::assertStringContainsString('or set UNLOCK_STALE=yes to let ArkHive do so', $maintain->stderr);
        self::assertLessThan(45, microtime(true) - $started, 'not waited for until LOCK_WAIT');
        self::assertCount(1, glob("{$repository}/locks/*") ?: [], 'never removed by default');

        $maintain = self::arkhive(['maintain', '--config', $this->config($repository, null, ['UNLOCK_STALE' => 'yes'])], $this->runEnv());
        self::assertSame(0, $maintain->exitCode, $maintain->output());
        self::assertStringContainsString("(PID {$pid} no longer exists); removing it with restic unlock (UNLOCK_STALE)", $maintain->stderr);
        self::assertSame([], glob("{$repository}/locks/*") ?: []);
    }

    public function testDoctorIsGreenOnAHealthyHostAndNamesWhatIsMissing(): void
    {
        [$repository, $sftpCommand] = self::sftpRepository();
        $this->arkhiveInit($this->dir, $repository, $sftpCommand);
        $config = $this->config($repository, $sftpCommand, $this->databaseConfig("{$this->dir}/dumps"));
        $this->backup($config);

        $doctor = $this->json(['doctor'], 0, $config);
        self::assertSame('ok', $doctor['status']);
        $statuses = [];
        foreach ((array) $doctor['checks'] as $check) {
            self::assertIsArray($check);
            $statuses[$check['check']] = $check['status'];
        }
        self::assertSame('ok', $statuses['SSH']);
        self::assertSame('ok', $statuses['repository']);
        self::assertSame('ok', $statuses['latest snapshot']);
        self::assertSame('ok', $statuses['dump space']);
        self::assertSame('ok', $statuses['clock']);

        $broken = $this->config($repository, $sftpCommand, ['RESTIC_BINARY' => '/nonexistent/restic']);
        $missing = self::arkhive(['doctor', '--config', $broken], $this->runEnv());
        self::assertSame(1, $missing->exitCode, $missing->output());
        self::assertStringContainsString('FAIL  restic             restic is needed for every backup and restore but /nonexistent/restic is not an executable file (from RESTIC_BINARY)', $missing->stdout);
        self::assertMatchesRegularExpression('/hint: Run: (apt install|dnf install|apk add|pacman -S|zypper install)/', $missing->stdout);
    }
}
