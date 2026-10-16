<?php

declare(strict_types=1);

namespace Tests\e2e;

use FilesystemIterator;
use Mfonte\Arkhive\ExitCode;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * spec §13.5, the restore matrix: the PHAR restores what it backed up to the SFTP server. Each case
 * checks the exit code, the output and the restored bytes (sha256), mtimes, modes and, as root,
 * owners. Cases that need root (owners, tmpfs, dropping to another user) are skipped otherwise; CI
 * runs this suite as root.
 */
final class RestoreTest extends E2eTestCase
{
    use DatabaseClients;

    private const OTHER_UID = 4321;
    private const NOBODY = 65534;

    private string $dir;
    private string $tree;
    private string $repository;
    private string $sftpCommand;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        chmod($this->dir, 0711);
        $this->tree = "{$this->dir}/site";
        [$this->repository, $this->sftpCommand] = self::sftpRepository();
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        mkdir("{$this->dir}/locks");
        mkdir("{$this->dir}/tmp", 0700);
    }

    private static function root(): bool
    {
        return posix_geteuid() === 0;
    }

    /**
     * @param array<string, string> $extra
     */
    private function config(array $extra = []): string
    {
        return $this->writeConfig("{$this->dir}/config", [
            'BACKUP_PATHS' => $this->tree,
            'RESTIC_REPOSITORY' => $this->repository,
            'RESTIC_SFTP_COMMAND' => $this->sftpCommand,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            'RESTIC_HOST' => 'e2e.example.com',
            ...$extra,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function runEnv(): array
    {
        return ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'TMPDIR' => "{$this->dir}/tmp"];
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $extra configuration keys
     */
    private function ark(array $args, array $extra = []): \Tests\Support\CliResult
    {
        return self::arkhive([...$args, '--config', $this->config($extra)], $this->runEnv());
    }

    /**
     * @param array<string, string> $extra configuration keys
     */
    private function backup(array $extra = []): string
    {
        $result = $this->ark(['backup', '--json'], $extra);
        self::assertSame(0, $result->exitCode, $result->output());
        $summary = json_decode($result->stdout, true);
        self::assertIsArray($summary);

        return (string) $summary['snapshot_id'];
    }

    /**
     * Runs an exclusive restic operation on a local repository as another host (a UTS namespace:
     * e2e runs as root), copies its lock file while it holds it (busy polling: a local repair lasts
     * far longer than one scan) and writes it back afterwards, as a crashed run leaves it.
     *
     * @return string the restored lock file
     */
    private function captureExclusiveLock(string $repository): string
    {
        $repair = proc_open(
            // from another hostname: restic ignores a lock of a dead process on its own host as stale
            ['unshare', '--uts', 'sh', '-c', 'hostname crashed.example.com && exec "$0" repair index --read-all-packs', self::env('E2E_RESTIC')],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$this->dir}/repair.stderr", 'w']],
            $pipes,
            null,
            self::environment(['RESTIC_REPOSITORY' => $repository, 'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE')]),
        );
        self::assertIsResource($repair);
        $captured = [];
        do {
            $running = proc_get_status($repair)['running'];
            foreach (glob("{$repository}/locks/*") ?: [] as $file) {
                if (preg_match('/^[0-9a-f]{64}$/', basename($file)) !== 1) {
                    continue; // restic writes "<id>-tmp-<n>" first, then renames it
                }
                $content = @file_get_contents($file);
                if (is_string($content) && $content !== '') {
                    $captured[$file] = $content;
                }
            }
        } while ($running);
        $exit = proc_close($repair);
        self::assertSame(0, $exit, 'the repair ran: ' . (string) @file_get_contents("{$this->dir}/repair.stderr"));
        self::assertNotSame([], $captured, 'the lock of the repair was seen while it ran');
        $file = (string) array_key_first($captured);
        file_put_contents($file, $captured[$file]);

        return $file;
    }

    /**
     * A small tree: text and binary files with set modes and mtimes, owned by another uid as root.
     */
    private function buildTree(): void
    {
        $files = [
            'index.php' => [0644, "<?php echo 'site';\n"],
            'config/secret.php' => [0600, "<?php return ['key' => 'placeholder'];\n"],
            'bin/run.sh' => [0755, "#!/bin/sh\necho run\n"],
            'data/blob.bin' => [0640, random_bytes(300000)],
            'data/more/notes.txt' => [0644, str_repeat("note\n", 2000)],
        ];
        foreach ($files as $relative => [$mode, $content]) {
            $path = "{$this->tree}/{$relative}";
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
            chmod($path, $mode);
            touch($path, 1700000000 + strlen($relative));
        }
        chmod("{$this->tree}/config", 0750);
        if (self::root()) {
            chown("{$this->tree}/data/blob.bin", self::OTHER_UID);
            chgrp("{$this->tree}/data/blob.bin", self::OTHER_UID);
            chown("{$this->tree}/config", self::OTHER_UID);
        }
        foreach (['config', 'bin', 'data/more', 'data', ''] as $dir) {
            touch(rtrim("{$this->tree}/{$dir}", '/'), 1690000000);
        }
    }

    /**
     * Everything a restore must reproduce, per relative path; symlink mtimes are not compared.
     *
     * @return array<string, array<string, int|string>>
     */
    private static function state(string $root): array
    {
        $state = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ([new SplFileInfo($root), ...$items] as $item) {
            assert($item instanceof SplFileInfo);
            $path = $item->getPathname();
            $stat = lstat($path);
            self::assertIsArray($stat);
            $relative = substr($path, strlen($root)) ?: '/';
            $entry = ['mode' => sprintf('%o', $stat['mode'])];
            if (is_link($path)) {
                $entry['target'] = (string) readlink($path);
            } else {
                $entry['mtime'] = $stat['mtime'];
            }
            if (is_file($path) && !is_link($path)) {
                $entry['size'] = $stat['size'];
                $entry['sha256'] = (string) hash_file('sha256', $path);
            }
            if (self::root()) {
                $entry['owner'] = "{$stat['uid']}:{$stat['gid']}";
            }
            $state[$relative] = $entry;
        }
        ksort($state);

        return $state;
    }

    public function testFullRestoreToANewDirectoryIsIdentical(): void
    {
        $this->buildTree();
        $snapshot = $this->backup();
        $target = "{$this->dir}/restored";

        $result = $this->ark(['restore', '--snapshot', 'latest', '--target', $target, '--yes', '--json']);

        self::assertSame(0, $result->exitCode, $result->output());
        $json = json_decode($result->stdout, true);
        self::assertIsArray($json);
        self::assertSame($snapshot, $json['snapshot']);
        self::assertTrue($json['verified']);
        self::assertSame(self::root() ? 'preserve' : 'current', $json['owner']);
        self::assertSame(self::state($this->tree), self::state($target . $this->tree));
        self::assertStringContainsString('success Restored and verified ', $result->stderr);
        self::assertStringContainsString("Review, then put it in place with: rsync -aHAX --numeric-ids '{$target}{$this->tree}/' '{$this->tree}/'", $result->stderr);
    }

    public function testIncludeOfAFolderAndOfASingleFile(): void
    {
        $this->buildTree();
        $this->backup();
        $target = "{$this->dir}/restored";

        $result = $this->ark(['restore', '--snapshot', 'latest', '--include', "{$this->tree}/data/more", '--include', "{$this->tree}/config/secret.php", '--target', $target, '--yes']);

        self::assertSame(0, $result->exitCode, $result->output());
        $restored = self::state($target . $this->tree);
        $files = array_keys(array_filter($restored, static fn (array $e): bool => isset($e['sha256'])));
        self::assertSame(['/config/secret.php', '/data/more/notes.txt'], $files);
        $original = self::state($this->tree);
        foreach ($files as $file) {
            self::assertSame($original[$file], $restored[$file], $file);
        }
    }

    public function testToStdoutIsByteIdentical(): void
    {
        $this->buildTree();
        $this->backup();

        $result = $this->ark(['restore', '--snapshot', 'latest', '--to-stdout', "{$this->tree}/data/blob.bin"]);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame(hash_file('sha256', "{$this->tree}/data/blob.bin"), hash('sha256', $result->stdout));
        self::assertStringNotContainsString('Restored', $result->stdout);
    }

    public function testDatabaseImportAsANewDatabase(): void
    {
        mkdir($this->tree, 0755, true);
        $databases = $this->databaseConfig("{$this->dir}/dumps");
        $this->backup($databases);
        $suffix = bin2hex(random_bytes(3));
        $before = [$this->checksum('shop.products'), $this->checksum('shop.orders'), $this->pgFingerprint('crm', 'contacts')];

        try {
            $mysql = $this->ark(['restore', '--snapshot', 'latest', '--db', 'shop', '--import-as', "shop_e2e_{$suffix}", '--yes', '--json'], $databases);
            self::assertSame(0, $mysql->exitCode, $mysql->output());
            foreach (['products', 'orders'] as $table) {
                self::assertSame($this->mysql("SELECT COUNT(*) FROM shop.{$table}"), $this->mysql("SELECT COUNT(*) FROM shop_e2e_{$suffix}.{$table}"));
                self::assertSame($this->checksum("shop.{$table}"), $this->checksum("shop_e2e_{$suffix}.{$table}"), $table);
            }
            self::assertSame('1', $this->mysql("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = 'shop_e2e_{$suffix}' AND routine_name = 'order_count'"));

            $pgsql = $this->ark(['restore', '--snapshot', 'latest', '--db', 'crm', '--import-as', "crm_e2e_{$suffix}", '--yes'], $databases);
            self::assertSame(0, $pgsql->exitCode, $pgsql->output());
            self::assertSame($this->pgFingerprint('crm', 'contacts'), $this->pgFingerprint("crm_e2e_{$suffix}", 'contacts'));

            $existing = $this->ark(['restore', '--snapshot', 'latest', '--db', 'shop', '--import-as', 'blog', '--yes'], $databases);
            self::assertSame(ExitCode::CANNOT_CREATE, $existing->exitCode, $existing->output());
            self::assertStringContainsString('The database blog already exists: --import-as creates a new database and never imports over an existing one', $existing->stderr);

            self::assertSame($before, [$this->checksum('shop.products'), $this->checksum('shop.orders'), $this->pgFingerprint('crm', 'contacts')], 'the original databases are untouched');
            self::assertSame([], glob("{$this->dir}/dumps/arkhive-import-*") ?: [], 'no restored dump left behind');
        } finally {
            $this->mysql("DROP DATABASE IF EXISTS shop_e2e_{$suffix}");
            $this->psql("DROP DATABASE IF EXISTS crm_e2e_{$suffix}");
        }
    }

    public function testInPlaceTakesASafetySnapshotThatUndoesTheRestore(): void
    {
        $this->buildTree();
        $this->backup();
        $original = self::state($this->tree);
        file_put_contents("{$this->tree}/index.php", "<?php echo 'broken';\n");
        file_put_contents("{$this->tree}/data/more/notes.txt", 'edited');
        $broken = self::state($this->tree);

        $refused = $this->ark(['restore', '--snapshot', 'latest', '--in-place', '--yes']);
        self::assertSame(ExitCode::USAGE, $refused->exitCode, $refused->output());
        self::assertSame($broken, self::state($this->tree), 'nothing changed without --force');

        $result = $this->ark(['restore', '--snapshot', 'latest', '--in-place', '--force', '--yes', '--json']);
        self::assertSame(0, $result->exitCode, $result->output());
        $json = json_decode($result->stdout, true);
        self::assertIsArray($json);
        $safety = (string) $json['safety_snapshot'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $safety);
        self::assertStringContainsString('success Safety snapshot ' . substr($safety, 0, 8) . ' saved (tag pre-restore)', $result->stderr);
        self::assertSame($original, self::state($this->tree), 'the snapshot is back in place');

        $listed = self::restic(['snapshots', '--json', '--tag', 'pre-restore'], $this->repository, $this->sftpCommand);
        self::assertSame([$safety], array_column((array) json_decode($listed->stdout, true), 'id'), 'the safety snapshot is listed');

        $undo = $this->ark(['restore', '--snapshot', substr($safety, 0, 8), '--in-place', '--force', '--yes']);
        self::assertSame(0, $undo->exitCode, $undo->output());
        self::assertSame($broken, self::state($this->tree), 'restoring the safety snapshot brings the pre-restore state back');
    }

    public function testANonEmptyTargetWithoutForceIsRefusedAndUntouched(): void
    {
        $this->buildTree();
        $this->backup();
        $target = "{$this->dir}/busy";
        mkdir($target);
        file_put_contents("{$target}/mine.txt", 'keep me');
        $before = self::state($target);

        $result = $this->ark(['restore', '--snapshot', 'latest', '--target', $target, '--yes']);

        self::assertSame(ExitCode::CANNOT_CREATE, $result->exitCode, $result->output());
        self::assertStringContainsString("error The restore target {$target} is not empty", $result->stderr);
        self::assertSame($before, self::state($target));
    }

    public function testInsufficientSpaceIsRefusedWithBothNumbers(): void
    {
        if (!self::root()) {
            self::markTestSkipped('mounting a small tmpfs needs root (CI runs the e2e suite as root)');
        }
        $this->buildTree();
        $this->backup();
        $small = "{$this->dir}/small";
        mkdir($small);
        $mount = self::exec(['mount', '-t', 'tmpfs', '-o', 'size=64k', 'tmpfs', $small]);
        self::assertSame(0, $mount->exitCode, $mount->stderr);
        try {
            $result = $this->ark(['restore', '--snapshot', 'latest', '--target', "{$small}/restore", '--yes']);

            self::assertSame(ExitCode::CANNOT_CREATE, $result->exitCode, $result->output());
            self::assertMatchesRegularExpression('/error Not enough free space in ' . preg_quote($small, '/') . ' for the restore: \d+\.\d\d KiB needed, \d+(\.\d\d)? (B|KiB) available/', $result->stderr);
            self::assertSame([], array_values(array_diff(scandir($small) ?: [], ['.', '..'])), 'nothing written');
        } finally {
            self::exec(['umount', $small]);
        }
    }

    public function testNonRootRestoreOfRootOwnedDataGetsTheCurrentOwnerWarning(): void
    {
        if (!self::root()) {
            self::markTestSkipped('needs root to own the data and to drop to another user (CI runs the e2e suite as root)');
        }
        $this->buildTree();
        $this->backup();
        // everything an unprivileged operator needs, owned by them
        $home = "{$this->dir}/nobody";
        mkdir($home, 0700);
        foreach (['phar' => self::env('E2E_PHAR'), 'restic' => self::env('E2E_RESTIC'), 'key' => self::env('E2E_SSH_KEY'), 'password' => self::env('E2E_PASSWORD_FILE')] as $name => $source) {
            copy($source, "{$home}/{$name}");
            chmod("{$home}/{$name}", in_array($name, ['key', 'password'], true) ? 0600 : 0755);
        }
        foreach (["{$home}", ...glob("{$home}/*") ?: []] as $path) {
            chown($path, self::NOBODY);
            chgrp($path, self::NOBODY);
        }
        $sftp = str_replace(self::env('E2E_SSH_KEY'), "{$home}/key", $this->sftpCommand);
        $target = "{$home}/restored";

        $result = self::exec(
            ['setpriv', '--reuid=' . self::NOBODY, '--regid=' . self::NOBODY, '--clear-groups', '--', PHP_BINARY, "{$home}/phar", 'restore', '--repo', $this->repository, '--password-file', "{$home}/password", '--sftp-command', $sftp, '--snapshot', 'latest', '--target', $target, '--yes'],
            ['HOME' => $home, 'PATH' => "{$home}:/usr/local/bin:/usr/bin:/bin", 'ARKHIVE_LOCK_DIR' => $home, 'TMPDIR' => $home],
        );

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertStringContainsString('warning Ownership was not restored: running as uid ' . self::NOBODY . ', the restored files belong to you', $result->stderr);
        clearstatcache();
        self::assertSame(self::NOBODY, fileowner("{$target}{$this->tree}/data/blob.bin"));
        self::assertSame(hash_file('sha256', "{$this->tree}/data/blob.bin"), hash_file('sha256', "{$target}{$this->tree}/data/blob.bin"));
    }

    public function testBareMetalWithoutAnyConfiguration(): void
    {
        $this->buildTree();
        $this->backup();
        $target = "{$this->dir}/bare";
        mkdir("{$this->dir}/empty-home", 0700);

        $result = self::arkhive(
            ['restore', '--repo', $this->repository, '--password-file', self::env('E2E_PASSWORD_FILE'), '--sftp-command', $this->sftpCommand, '--snapshot', 'latest', '--target', $target, '--yes'],
            ['HOME' => "{$this->dir}/empty-home", 'XDG_CONFIG_HOME' => "{$this->dir}/empty-home/.config", ...$this->runEnv()],
            "{$this->dir}/empty-home",
        );

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertSame(self::state($this->tree), self::state($target . $this->tree));
    }

    public function testWrongPasswordUnreachableRepositoryAndStaleLockAreExplained(): void
    {
        $this->buildTree();
        $this->backup();
        $target = "{$this->dir}/never";

        $wrong = "{$this->dir}/wrong-password";
        file_put_contents($wrong, "not-the-password\n");
        chmod($wrong, 0600);
        $result = $this->ark(['restore', '--snapshot', 'latest', '--target', $target, '--yes'], ['RESTIC_PASSWORD_FILE' => $wrong]);
        self::assertSame(ExitCode::NO_PERMISSION, $result->exitCode, $result->output());
        self::assertStringContainsString("error Wrong password for the restic repository {$this->repository}", $result->stderr);

        $unreachable = str_replace('-p ' . self::env('E2E_SFTP_PORT'), '-p 1', $this->sftpCommand);
        $result = $this->ark(['restore', '--snapshot', 'latest', '--target', $target, '--yes'], ['RESTIC_SFTP_COMMAND' => $unreachable]);
        self::assertSame(ExitCode::UNAVAILABLE, $result->exitCode, $result->output());
        self::assertStringContainsString('error Opening the restic repository failed (restic exit ', $result->stderr);
        self::assertStringContainsString('(attempt 3 of 3)', $result->stderr, 'a connection failure is tried three times');
        self::assertStringContainsString('notice Check that the repository server is up and that this command works by hand: ' . $unreachable, $result->stderr);

        // a stale exclusive lock, as a run killed while holding it leaves it behind. Deterministic on
        // any runner: on a local repository the lock file of a repair is copied while it exists and
        // put back once the repair is over (timing a kill against the SFTP repository was flaky).
        $local = "{$this->dir}/local-repo";
        $this->arkhiveInit($this->dir, $local, null);
        $this->backup(['RESTIC_REPOSITORY' => $local]);
        $lock = $this->captureExclusiveLock($local);
        try {
            $result = $this->ark(['restore', '--snapshot', 'latest', '--target', $target, '--yes'], ['RESTIC_REPOSITORY' => $local, 'LOCK_WAIT' => '8']);
            self::assertSame(ExitCode::TEMPORARY, $result->exitCode, $result->output());
            self::assertMatchesRegularExpression('/warning .*: The restic repository is locked.*; trying again in \d+s \(waiting up to 8s in all, \d+s so far\)/', $result->stderr);
            self::assertStringContainsString('error The restic repository is locked', $result->stderr);
            self::assertStringContainsString("restic -r '{$local}' unlock (ArkHive never does this automatically)", $result->stderr);
            self::assertFileExists($lock, 'ArkHive did not remove the lock');
        } finally {
            self::restic(['unlock', '--remove-all'], $local, null);
        }
        self::assertFileDoesNotExist($target, 'no partial target');
    }

    public function testSigtermDuringARestoreCleansUp(): void
    {
        mkdir("{$this->tree}", 0755, true);
        file_put_contents("{$this->tree}/large.bin", random_bytes(16 * 1024 * 1024));
        $this->backup();
        $slow = "{$this->dir}/slow-restic";
        file_put_contents($slow, sprintf("#!/bin/sh\nexec %s --limit-download 256 \"\$@\"\n", escapeshellarg(self::env('E2E_RESTIC'))));
        chmod($slow, 0755);
        $target = "{$this->dir}/interrupted";

        $started = self::startArkhive(['restore', '--config', $this->config(['RESTIC_BINARY' => $slow]), '--snapshot', 'latest', '--target', $target, '--yes'], $this->runEnv());
        self::assertTrue(self::waitFor(static fn (): bool => is_dir($target), 120), 'the restore started writing');
        proc_terminate($started[0], 15);
        $result = self::finish($started);

        self::assertSame(ExitCode::SIGTERM, $result->exitCode, $result->output());
        self::assertStringContainsString('error Interrupted by SIGTERM: cleaned up and released the lock', $result->stderr);
        self::assertFileDoesNotExist($target, 'the partly restored target is removed');
        self::assertSame([], glob("{$this->dir}/tmp/*") ?: [], 'no temp file left');
        self::assertTrue(self::waitFor(fn (): bool => trim(self::restic(['list', 'locks', '--no-lock'], $this->repository, $this->sftpCommand)->stdout) === '', 30), 'restic released its lock');
    }

    public function testSpecialFilesArePreserved(): void
    {
        mkdir("{$this->tree}/empty/nested-empty", 0755, true);
        mkdir("{$this->tree}/with space", 0755, true);
        file_put_contents("{$this->tree}/with space/naïve ünïcode €.txt", "unicode\n");
        file_put_contents("{$this->tree}/new\nline.txt", "newline in the name\n");
        $deep = $this->tree . str_repeat('/deep', 60);
        mkdir($deep, 0755, true);
        file_put_contents("{$deep}/leaf.txt", "deep\n");
        file_put_contents("{$this->tree}/target.txt", "linked\n");
        symlink('target.txt', "{$this->tree}/link");
        symlink('nowhere/at/all', "{$this->tree}/dangling");
        file_put_contents("{$this->tree}/hard-a", "hard link content\n");
        link("{$this->tree}/hard-a", "{$this->tree}/hard-b");
        // a 5 GiB sparse file with data in the middle and at the end
        $sparse = fopen("{$this->tree}/sparse.img", 'c');
        self::assertIsResource($sparse);
        fseek($sparse, 2 * 1024 ** 3);
        fwrite($sparse, 'middle');
        ftruncate($sparse, 5 * 1024 ** 3);
        fseek($sparse, 5 * 1024 ** 3 - 3);
        fwrite($sparse, 'end');
        fclose($sparse);
        $this->backup();
        $target = "{$this->dir}/special";

        $result = $this->ark(['restore', '--snapshot', 'latest', '--target', $target, '--yes']);

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertSame(self::state($this->tree), self::state($target . $this->tree));
        $restored = $target . $this->tree;
        self::assertSame(fileinode("{$restored}/hard-a"), fileinode("{$restored}/hard-b"), 'hard links stay linked');
        self::assertSame('nowhere/at/all', readlink("{$restored}/dangling"));
        $stat = stat("{$restored}/sparse.img");
        self::assertIsArray($stat);
        self::assertSame(5 * 1024 ** 3, $stat['size']);
        self::assertLessThan(64 * 1024 * 1024, $stat['blocks'] * 512, 'the sparse file stays sparse');
    }

    public function testWizardUnderAPseudoTerminalWithDefaultsOnly(): void
    {
        $this->buildTree();
        $this->backup();
        $cwd = "{$this->dir}/operator";
        mkdir($cwd);
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, self::env('E2E_PHAR'), 'restore', '--config', "{$this->dir}/config"]));

        $result = self::exec(['script', '-qec', $command, '/dev/null'], [...$this->runEnv(), 'TERM' => 'xterm'], $cwd, str_repeat("\n", 10));

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertStringContainsString('Start the restore? [Y/n]', $result->stdout);
        $restored = glob("{$cwd}/arkhive-restore-e2e.example.com-*") ?: [];
        self::assertCount(1, $restored, $result->output());
        self::assertSame(self::state($this->tree), self::state($restored[0] . $this->tree));
    }

    public function testUnattendedWithoutSnapshotOrTargetNamesTheFlag(): void
    {
        $this->buildTree();
        $this->backup();

        foreach ([[[], '--snapshot'], [['--snapshot', 'latest'], '--target'], [['--snapshot', 'latest', '--target', "{$this->dir}/t"], '--yes']] as [$args, $flag]) {
            $result = $this->ark(['restore', ...$args]);
            self::assertSame(ExitCode::USAGE, $result->exitCode, $result->output());
            self::assertStringContainsString("is required in unattended mode: pass {$flag}", $result->stderr);
            self::assertStringNotContainsString('[Y/n]', $result->output());
        }
        self::assertFileDoesNotExist("{$this->dir}/t");
    }
}
