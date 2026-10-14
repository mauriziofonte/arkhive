<?php

declare(strict_types=1);

namespace Tests\e2e;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * spec §10.4: the README "My server is gone" procedure, run as written. The block marked
 * `<!-- tested: server-gone -->` is executed line by line on a "fresh host" (no configuration, an
 * empty HOME and working directory) against a repository on the SFTP server, with $REPO, $SSH,
 * $PW and $TARGET set as the README's previous block tells the reader to.
 */
final class ServerGoneTest extends E2eTestCase
{
    private const MARKER = '<!-- tested: server-gone -->';

    public function testTheReadmeProcedureRestoresTheLostServer(): void
    {
        $lines = self::procedure();
        $dir = $this->tempDir();
        $site = "{$dir}/site";
        mkdir("{$site}/uploads", 0755, true);
        file_put_contents("{$site}/index.php", "<?php echo 'hello';\n");
        file_put_contents("{$site}/uploads/photo.bin", random_bytes(200 * 1024));
        [$repository, $sftpCommand] = self::sftpRepository();
        $this->arkhiveInit($dir, $repository, $sftpCommand);
        $config = $this->writeConfig("{$dir}/config", [
            'BACKUP_PATHS' => $site,
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            'RESTIC_SFTP_COMMAND' => $sftpCommand,
        ]);
        $backup = self::arkhive(['backup', '--config', $config]);
        self::assertSame(0, $backup->exitCode, $backup->output());

        // the new host: arkhive installed in PATH, nothing else of the old one
        $host = "{$dir}/new-host";
        mkdir("{$host}/home", 0700, true);
        mkdir("{$host}/bin");
        copy(self::env('E2E_PHAR'), "{$host}/bin/arkhive");
        chmod("{$host}/bin/arkhive", 0755);
        $env = [
            'PATH' => "{$host}/bin:" . self::path(),
            'HOME' => "{$host}/home",
            'REPO' => $repository,
            'SSH' => $sftpCommand,
            'PW' => self::env('E2E_PASSWORD_FILE'),
            'TARGET' => "{$host}/restored",
        ];

        foreach ($lines as $line) {
            $result = self::exec(['/bin/sh', '-c', $line], $env, "{$host}/home");
            // doctor exits 3 when it only has warnings (no configuration file is one on a fresh host)
            $accepted = str_starts_with($line, 'arkhive doctor ') ? [0, 3] : [0];
            self::assertContains($result->exitCode, $accepted, "{$line}\n{$result->output()}");
        }

        self::assertSame(self::tree($site), self::tree("{$host}/restored{$site}"), 'the procedure restored the lost site');
    }

    /**
     * The command lines of the tested block of README.md.
     *
     * @return list<string>
     */
    private static function procedure(): array
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $section = strstr($readme, "\n## My server is gone\n");
        self::assertIsString($section, 'README.md has a "My server is gone" section');
        $section = (string) preg_replace('/\n## .*$/s', '', substr($section, 1));
        self::assertSame(1, preg_match('/' . preg_quote(self::MARKER, '/') . '\n```sh\n(.*?)\n```/s', $section, $m), 'the section has a tested sh block');
        $lines = array_values(array_filter(explode("\n", $m[1]), static fn (string $line): bool => trim($line) !== ''));
        foreach ($lines as $line) {
            self::assertStringStartsWith('arkhive ', $line, 'one arkhive command per line');
        }
        self::assertNotSame([], preg_grep('/^arkhive restore .*--target "\$TARGET"/', $lines), 'the procedure restores into $TARGET');

        return $lines;
    }

    /**
     * Relative path => sha256 and mode.
     *
     * @return array<string, string>
     */
    private static function tree(string $root): array
    {
        self::assertDirectoryExists($root);
        $state = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $mode = sprintf('%04o', $item->getPerms() & 07777);
            $state[substr($item->getPathname(), strlen($root))] = $item->isFile() ? hash_file('sha256', $item->getPathname()) . " {$mode}" : "dir {$mode}";
        }
        ksort($state);

        return $state;
    }
}
