<?php

declare(strict_types=1);

namespace Tests\Compat;

use Mfonte\Arkhive\ExitCode;

/**
 * Every ArkHive command that talks to restic, in the order an operator uses them, against the
 * restic under test.
 */
final class LifecycleTest extends CompatTestCase
{
    public function testFromInitToDrill(): void
    {
        $dir = $this->tempDir();
        $source = "{$dir}/www";
        mkdir($source);
        self::sourceTree($source);
        $repository = "{$dir}/repo";
        $passwordFile = "{$dir}/secret/restic-password";
        mkdir(dirname($passwordFile), 0700);
        $config = self::writeConfig("{$dir}/arkhive.conf", [
            'BACKUP_PATHS' => $source,
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD_FILE' => $passwordFile,
            'RESTIC_BINARY' => self::restic(),
            'RESTIC_HOST' => 'web1.example.com',
            'EXCLUSION_PATTERNS' => '/cache,*.log',
        ]);
        $version = self::resticVersion();

        // init: generates the password, creates the repository, and is idempotent
        $init = self::arkhive(['init', '--config', $config, '--json']);
        self::assertSame(ExitCode::SUCCESS, $init->exitCode, $init->output());
        self::assertTrue(self::json($init)['created'] ?? null, $init->stdout);
        self::assertMatchesRegularExpression('/^[0-9a-f]{10,64}$/', (string) (self::json($init)['id'] ?? ''), $init->stdout);
        $again = self::arkhive(['init', '--config', $config]);
        self::assertSame(ExitCode::SUCCESS, $again->exitCode, $again->output());
        self::assertStringContainsString('already exists and the password opens it', $again->stderr);
        $format = json_decode(self::resticRun(['cat', 'config'], $repository, $passwordFile)->stdout, true);
        self::assertSame(version_compare($version, '0.14.0', '>=') ? 2 : 1, is_array($format) ? $format['version'] : null, 'the repository format follows what this restic writes');

        // backup: the snapshot id is always the full one
        $backup = self::arkhive(['backup', '--config', $config, '--json']);
        self::assertSame(ExitCode::SUCCESS, $backup->exitCode, $backup->output());
        $snapshot = (string) (self::json($backup)['snapshot_id'] ?? '');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot, $backup->stdout);
        $second = self::arkhive(['backup', '--config', $config]);
        self::assertSame(ExitCode::SUCCESS, $second->exitCode, $second->output());

        // status, ls, find
        $status = self::arkhive(['status', '--config', $config, '--json']);
        self::assertSame(ExitCode::SUCCESS, $status->exitCode, $status->output());
        self::assertSame(2, self::json($status)['snapshots'] ?? null, $status->stdout);
        $ls = self::arkhive(['ls', $snapshot, "{$source}/site", '--config', $config]);
        self::assertSame(ExitCode::SUCCESS, $ls->exitCode, $ls->output());
        self::assertStringContainsString('index.html', $ls->stdout);
        self::assertStringContainsString('with space.txt', $ls->stdout);
        $find = self::arkhive(['find', 'logo.bin', '--config', $config]);
        self::assertSame(ExitCode::SUCCESS, $find->exitCode, $find->output());
        self::assertStringContainsString("{$source}/site/assets/logo.bin", $find->stdout);
        foreach (['session.tmp', 'debug.log'] as $name) {
            $excluded = self::arkhive(['find', $name, '--config', $config]);
            self::assertStringNotContainsString($name, $excluded->stdout, 'EXCLUSION_PATTERNS kept it out');
        }

        // restore a directory into a new target, verified, with totals
        $target = "{$dir}/restored";
        $restore = self::arkhive(['restore', '--config', $config, '--snapshot', $snapshot, '--include', "{$source}/site/assets", '--target', $target, '--yes', '--json']);
        self::assertSame(ExitCode::SUCCESS, $restore->exitCode, $restore->output());
        self::assertSame(file_get_contents("{$source}/site/assets/logo.bin"), file_get_contents("{$target}{$source}/site/assets/logo.bin"));
        self::assertSame(1 << 20, filesize("{$target}{$source}/site/assets/zeros.img"));
        self::assertFileDoesNotExist("{$target}{$source}/site/index.html");
        $report = self::json($restore);
        self::assertSame(70000 + (1 << 20), $report['bytes'] ?? null, $restore->stdout);
        self::assertGreaterThanOrEqual(3, $report['files'] ?? 0, $restore->stdout);
        self::assertTrue($report['verified'] ?? null);

        // a single file to stdout
        $stdout = self::arkhive(['restore', '--config', $config, '--snapshot', 'latest', '--to-stdout', "{$source}/site/with space.txt", '--yes']);
        self::assertSame(ExitCode::SUCCESS, $stdout->exitCode, $stdout->stderr);
        self::assertSame("a file name with a space\n", $stdout->stdout);

        // maintenance and a restore drill
        $maintain = self::arkhive(['maintain', '--config', $config, '--json']);
        self::assertSame(ExitCode::SUCCESS, $maintain->exitCode, $maintain->output());
        self::assertTrue(self::json($maintain)['healthy'] ?? null, $maintain->stdout);
        $drill = self::arkhive(['drill', '--config', $config, '--sample', '3']);
        self::assertSame(ExitCode::SUCCESS, $drill->exitCode, $drill->output());
        self::assertStringContainsString('Restore drill PASSED', $drill->stderr);

        // doctor names this restic
        $doctor = self::arkhive(['doctor', '--config', $config]);
        self::assertStringContainsString("restic {$version} at " . self::restic(), $doctor->output());
    }

    public function testAWrongPasswordIsExit77AndAMissingRepository69(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/www");
        file_put_contents("{$dir}/password", "not-the-password\n");
        chmod("{$dir}/password", 0600);
        $missing = self::writeConfig("{$dir}/missing.conf", ['BACKUP_PATHS' => "{$dir}/www", 'RESTIC_REPOSITORY' => "{$dir}/nothing-here", 'RESTIC_PASSWORD_FILE' => "{$dir}/password", 'RESTIC_BINARY' => self::restic()]);

        $status = self::arkhive(['status', '--config', $missing]);
        self::assertSame(ExitCode::UNAVAILABLE, $status->exitCode, $status->output());
        self::assertStringContainsString("There is no restic repository at {$dir}/nothing-here", $status->stderr);

        $init = self::resticRun(['init'], "{$dir}/repo", "{$dir}/password");
        self::assertSame(0, $init->exitCode, $init->output());
        file_put_contents("{$dir}/other", "another-password\n");
        chmod("{$dir}/other", 0600);
        $wrong = self::writeConfig("{$dir}/wrong.conf", ['BACKUP_PATHS' => "{$dir}/www", 'RESTIC_REPOSITORY' => "{$dir}/repo", 'RESTIC_PASSWORD_FILE' => "{$dir}/other", 'RESTIC_BINARY' => self::restic()]);
        $backup = self::arkhive(['backup', '--config', $wrong]);
        self::assertSame(ExitCode::NO_PERMISSION, $backup->exitCode, $backup->output());
        self::assertStringContainsString('Wrong password for the restic repository', $backup->stderr);
    }

    public function testARepositoryOfFormatTwoNeedsRestic014(): void
    {
        $newer = getenv('ARKHIVE_TEST_RESTIC_V2');
        if ($newer === false || $newer === '' || version_compare(self::resticVersion(), '0.14.0', '>=')) {
            self::markTestSkipped('needs restic older than 0.14.0 and ARKHIVE_TEST_RESTIC_V2 (a restic that writes format 2)');
        }
        $dir = $this->tempDir();
        mkdir("{$dir}/www");
        file_put_contents("{$dir}/password", "compat-password\n");
        chmod("{$dir}/password", 0600);
        $init = self::exec([$newer, 'init'], ['RESTIC_REPOSITORY' => "{$dir}/repo", 'RESTIC_PASSWORD_FILE' => "{$dir}/password"]);
        self::assertSame(0, $init->exitCode, $init->output());
        $config = self::writeConfig("{$dir}/arkhive.conf", ['BACKUP_PATHS' => "{$dir}/www", 'RESTIC_REPOSITORY' => "{$dir}/repo", 'RESTIC_PASSWORD_FILE' => "{$dir}/password", 'RESTIC_BINARY' => self::restic()]);

        $backup = self::arkhive(['backup', '--config', $config]);

        self::assertSame(ExitCode::UNAVAILABLE, $backup->exitCode, $backup->output());
        self::assertStringContainsString('it uses a newer repository format', $backup->stderr);
        self::assertStringContainsString('needs restic 0.14.0 or newer', $backup->stderr);
    }
}
