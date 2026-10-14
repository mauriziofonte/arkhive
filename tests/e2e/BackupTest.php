<?php

declare(strict_types=1);

namespace Tests\e2e;

use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Services\Exclusion\ResticPatternMatcher;

/**
 * spec §13.2 steps 1-4 and 8-11: the PHAR backs up a generated tree and the seeded databases to the
 * SFTP server (non-standard port through RESTIC_SFTP_COMMAND).
 */
final class BackupTest extends E2eTestCase
{
    private string $dir;
    private string $tree;
    private string $repository;
    private string $sftpCommand;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->tree = "{$this->dir}/site";
        [$this->repository, $this->sftpCommand] = self::sftpRepository();
        $this->arkhiveInit($this->dir, $this->repository, $this->sftpCommand);
        mkdir("{$this->dir}/locks");
    }

    /**
     * @return list<string> the example's 1.x exclusion patterns
     */
    private static function examplePatterns(): array
    {
        $example = (string) file_get_contents(dirname(__DIR__, 2) . '/.arkhive-config.example');
        self::assertSame(1, preg_match('/^EXCLUSION_PATTERNS="([^"]+)"/m', $example, $match));

        return ExclusionConverter::split($match[1]);
    }

    /**
     * A file path (relative to the tree) that the 1.x pattern excludes.
     */
    private static function sampleFor(string $pattern): string
    {
        $directory = str_ends_with($pattern, '/*');
        $name = trim(preg_replace('#^(\*/)+|(/\*)+$#', '', $pattern) ?? '', '/');
        $name = str_replace('*', 'sample', $name);

        return $directory ? "deep/er/{$name}/inside.txt" : "deep/er/{$name}";
    }

    /**
     * spec step 1: random binary and text files, nested directories, a file matching every exclusion
     * pattern, a file with spaces and a symlink.
     *
     * @return array{list<string>, list<string>} [kept, excluded] relative paths
     */
    private function buildTree(): array
    {
        $kept = ['index.php', 'assets/logo.svg', 'docs/readme.md', 'my file with spaces.txt'];
        for ($i = 0; $i < 100; $i++) {
            $kept[] = sprintf('data/%02d/nested/blob-%03d.dat', $i % 10, $i);
        }
        foreach ($kept as $i => $relative) {
            $path = "{$this->tree}/{$relative}";
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, str_ends_with($relative, '.dat') ? random_bytes(20000 + $i * 997) : "text file {$relative}\n" . str_repeat("line {$i}\n", 50));
        }
        symlink('index.php', "{$this->tree}/link-to-index");
        $kept[] = 'link-to-index';

        $excluded = [];
        foreach (self::examplePatterns() as $pattern) {
            $relative = self::sampleFor($pattern);
            $path = "{$this->tree}/{$relative}";
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, "excluded by {$pattern}\n");
            $excluded[] = $relative;
        }

        return [$kept, array_values(array_unique($excluded))];
    }

    /**
     * @param array<string, string> $extra
     */
    private function config(array $extra = []): string
    {
        return $this->writeConfig("{$this->dir}/config", [
            'BACKUP_PATHS' => $this->tree,
            'EXCLUSION_PATTERNS' => implode(',', self::examplePatterns()),
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
    private function databases(): array
    {
        return [
            'DUMP_DIRECTORY' => "{$this->dir}/dumps",
            'WITH_MYSQL' => 'true',
            'MYSQL_HOST' => self::env('E2E_MYSQL_HOST'),
            'MYSQL_PORT' => self::env('E2E_MYSQL_PORT'),
            'MYSQL_USER' => self::env('E2E_MYSQL_USER'),
            'MYSQL_PASSWORD' => self::env('E2E_MYSQL_PASSWORD'),
            'MYSQL_DATABASES' => 'shop blog',
            'WITH_PGSQL' => 'true',
            'PGSQL_HOST' => self::env('E2E_PGSQL_HOST'),
            'PGSQL_PORT' => self::env('E2E_PGSQL_PORT'),
            'PGSQL_USER' => self::env('E2E_PGSQL_USER'),
            'PGSQL_PASSWORD' => self::env('E2E_PGSQL_PASSWORD'),
            'PGSQL_DATABASES' => 'inventory crm',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function runEnv(): array
    {
        return ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks", 'TMPDIR' => $this->privateTmp()];
    }

    private function privateTmp(): string
    {
        $tmp = "{$this->dir}/tmp";
        if (!is_dir($tmp)) {
            mkdir($tmp, 0700);
        }

        return $tmp;
    }

    /**
     * @return array<string, mixed>
     */
    private function backup(string $config): array
    {
        $result = self::arkhive(['backup', '--config', $config, '--json'], $this->runEnv());
        self::assertSame(0, $result->exitCode, $result->output());
        $summary = json_decode($result->stdout, true);
        self::assertIsArray($summary, $result->output());

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function snapshots(): array
    {
        $result = self::restic(['snapshots', '--json'], $this->repository, $this->sftpCommand);
        self::assertSame(0, $result->exitCode, $result->stderr);
        $snapshots = json_decode($result->stdout, true);
        self::assertIsArray($snapshots);

        return array_map(static fn (array $s): string => (string) $s['id'], $snapshots);
    }

    /**
     * @return list<string>
     */
    private function listed(string $snapshot = 'latest'): array
    {
        $result = self::restic(['ls', $snapshot], $this->repository, $this->sftpCommand);
        self::assertSame(0, $result->exitCode, $result->stderr);

        return array_values(array_filter(explode("\n", $result->stdout), static fn (string $l): bool => str_starts_with($l, '/')));
    }

    public function testBackupExcludesWhatThe1xPatternsExcludedAndDeduplicates(): void
    {
        [$kept, $excluded] = $this->buildTree();
        $config = $this->config();

        $first = $this->backup($config);
        self::assertCount(1, $this->snapshots(), 'one snapshot');
        self::assertSame($first['snapshot_id'], $this->snapshots()[0]);

        $listed = $this->listed();
        foreach ($kept as $relative) {
            self::assertContains("{$this->tree}/{$relative}", $listed, "{$relative} is in the snapshot");
        }
        foreach ($excluded as $relative) {
            self::assertNotContains("{$this->tree}/{$relative}", $listed, "{$relative} is excluded");
        }
        // the converted patterns agree with the restic matcher emulation used in the unit tests
        $patterns = ExclusionConverter::convert(self::examplePatterns(), [$this->tree]);
        foreach ($excluded as $relative) {
            self::assertTrue(ResticPatternMatcher::excludes($patterns, "{$this->tree}/{$relative}"), $relative);
        }

        // step 4: change 1% of the files, the second run uploads a small fraction
        foreach (['data/00/nested/blob-000.dat'] as $relative) {
            file_put_contents("{$this->tree}/{$relative}", random_bytes(20000));
        }
        $second = $this->backup($config);
        self::assertCount(2, $this->snapshots());
        self::assertSame(1, $second['files_changed']);
        self::assertLessThan(0.1 * (int) $first['data_added'], (int) $second['data_added'], 'deduplication: the second run adds a small fraction');
        self::assertSame([], glob($this->privateTmp() . '/*') ?: [], 'no temp files left');
    }

    public function testDumpsAreInTheSnapshotAndPasswordsNeverInTheProcessList(): void
    {
        $this->buildTree();
        $config = $this->config($this->databases());

        $sampler = new ProcessSampler("{$this->dir}/samples");
        try {
            $summary = $this->backup($config);
        } finally {
            $seen = $sampler->stop();
        }

        self::assertSame(['shop', 'blog', 'inventory', 'crm'], array_map(static fn (array $d): string => $d['database'], $summary['dumps']));
        $listed = $this->listed();
        foreach (['mysql/shop.sql', 'mysql/blog.sql', 'pgsql/inventory.sql', 'pgsql/crm.sql'] as $dump) {
            self::assertContains("{$this->dir}/dumps/{$dump}", $listed);
        }
        self::assertSame([], glob("{$this->dir}/dumps/*/*") ?: [], 'the dump directory is emptied after the backup');
        self::assertMatchesRegularExpression('/(mariadb-dump|mysqldump|pg_dump|restic)/', $seen, 'the sampler saw the child processes');
        self::assertStringNotContainsString(self::env('E2E_MYSQL_PASSWORD'), $seen);
        self::assertStringNotContainsString(self::env('E2E_PGSQL_PASSWORD'), $seen);
        self::assertStringNotContainsString(trim((string) file_get_contents(self::env('E2E_PASSWORD_FILE'))), $seen);
        self::assertSame([], glob($this->privateTmp() . '/*') ?: [], 'credentials files removed');
    }

    /**
     * A restic that waits before doing anything, so that a run holds the lock long enough.
     */
    private function slowRestic(float $seconds): string
    {
        $path = "{$this->dir}/slow-restic";
        file_put_contents($path, sprintf("#!/bin/sh\nsleep %s\nexec %s \"\$@\"\n", $seconds, escapeshellarg(self::env('E2E_RESTIC'))));
        chmod($path, 0755);

        return $path;
    }

    private function lockHeld(): bool
    {
        foreach (glob("{$this->dir}/locks/arkhive-*.lock") ?: [] as $lock) {
            if (str_contains((string) file_get_contents($lock), 'operation=backup')) {
                return true;
            }
        }

        return false;
    }

    public function testAParallelBackupFailsOnTheLock(): void
    {
        $this->buildTree();
        $config = $this->config(['RESTIC_BINARY' => $this->slowRestic(2)]);

        $first = self::startArkhive(['backup', '--config', $config], $this->runEnv());
        self::assertTrue(self::waitFor($this->lockHeld(...)), 'the first run takes the lock');
        $second = self::arkhive(['backup', '--config', $config], $this->runEnv());
        $firstResult = self::finish($first);

        self::assertSame(ExitCode::TEMPORARY, $second->exitCode, $second->output());
        self::assertStringContainsString('error Another ArkHive run holds the lock for this repository (PID ', $second->stderr);
        self::assertSame(0, $firstResult->exitCode, $firstResult->output());
        self::assertCount(1, $this->snapshots());
    }

    public function testSigtermStopsTheBackupCleanly(): void
    {
        $this->buildTree();
        $config = $this->config(['RESTIC_BINARY' => $this->slowRestic(3), ...$this->databases()]);

        $started = self::startArkhive(['backup', '--config', $config], $this->runEnv());
        // the dumps are written: restic backup (behind the slow wrapper) is what runs now
        self::assertTrue(self::waitFor(fn (): bool => count(glob("{$this->dir}/dumps/*/*.sql") ?: []) === 4, 60));
        proc_terminate($started[0], 15);
        $result = self::finish($started);

        self::assertSame(ExitCode::SIGTERM, $result->exitCode, $result->output());
        self::assertStringContainsString('error Interrupted by SIGTERM: cleaned up and released the lock', $result->stderr);
        self::assertFalse($this->lockHeld(), 'lock released');
        self::assertSame([], glob("{$this->dir}/dumps/*/*") ?: [], 'no dump left');
        self::assertSame([], glob($this->privateTmp() . '/*') ?: [], 'no temp file left');
        self::assertSame([], $this->snapshots(), 'no snapshot from an interrupted run');
    }

    public function testAWorldReadablePasswordFileIsRefused(): void
    {
        $password = "{$this->dir}/password";
        copy(self::env('E2E_PASSWORD_FILE'), $password);
        chmod($password, 0644);
        mkdir($this->tree, 0755, true);

        $result = self::arkhive(['backup', '--config', $this->config(['RESTIC_PASSWORD_FILE' => $password])], $this->runEnv());

        self::assertSame(ExitCode::CONFIG, $result->exitCode, $result->output());
        self::assertStringContainsString("RESTIC_PASSWORD_FILE {$password} is readable by group or others (mode 0644) (Run: chmod 600 {$password})", $result->stderr);
        self::assertSame([], $this->snapshots());
    }

    public function testA1xConfigListsEveryRemovedKey(): void
    {
        $legacy = "{$this->dir}/legacy";
        copy(dirname(__DIR__) . '/Fixtures/config/legacy-1.x.arkhive-config', $legacy);
        chmod($legacy, 0600);

        $result = self::arkhive(['backup', '--config', $legacy], $this->runEnv());

        self::assertSame(ExitCode::CONFIG, $result->exitCode, $result->output());
        foreach (['BACKUP_DIRECTORY', 'BACKUP_RETENTION_DAYS', 'COMPRESSION_TYPE', 'SSH_HOST', 'SSH_PORT', 'SSH_USER', 'SSH_BACKUP_HOME', 'WITH_CRYPT', 'CRYPT_PASSWORD', 'SMTP_AUTH', 'SMTP_SSL'] as $key) {
            self::assertStringContainsString("{$key}: ", $result->stderr, $key);
        }
        self::assertStringContainsString('Migration guide: https://github.com/mauriziofonte/arkhive#migrating-from-1x', $result->stderr);
    }
}
