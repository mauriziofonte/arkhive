<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Archives made exactly as ArkHive 1.x made them: `tar -cf - -T <list> | <gzip|xz -9> | openssl enc
 * -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass env:ARKHIVE_CRYPT_PASSWORD`, with 1.x file names.
 */
final class LegacyArchives
{
    public const PASSWORD = 'p"a ss\'$x;#1.x';

    /**
     * A small tree with the cases a backup has: nested directories, a binary file, odd names, a
     * symlink and a restrictive mode.
     */
    public static function tree(string $root): void
    {
        mkdir("{$root}/srv/www/site/assets", 0755, true);
        mkdir("{$root}/srv/www/odd name [1]", 0755, true);
        file_put_contents("{$root}/srv/www/site/index.php", "<?php echo 'hello';\n");
        file_put_contents("{$root}/srv/www/site/assets/blob.bin", random_bytes(200000));
        file_put_contents("{$root}/srv/www/odd name [1]/è ü.txt", "unicode\n");
        file_put_contents("{$root}/srv/www/site/.env", "SECRET=placeholder\n");
        chmod("{$root}/srv/www/site/.env", 0600);
        symlink('index.php', "{$root}/srv/www/site/link.php");
    }

    /**
     * @param 'gzip'|'xz'|'none' $compression
     * @param list<string>|null  $list        the -T file list; 1.x wrote absolute paths (default: "srv", relative to $root)
     *
     * @return string the archive path
     */
    public static function make(string $root, string $outDir, string $compression, bool $encrypt, string $password = self::PASSWORD, ?array $list = null): string
    {
        $ext = ['gzip' => '.arbk', 'xz' => '.arbk.xz', 'none' => '.tar'][$compression];
        $archive = $outDir . '/2024-05-01-web-arkhive' . ($encrypt ? '.enc' : '') . $ext;
        $stages = ['tar -cf - -T ' . escapeshellarg("{$outDir}/files.list")];
        if ($compression !== 'none') {
            $stages[] = $compression === 'gzip' ? 'gzip' : 'xz -9';
        }
        if ($encrypt) {
            $stages[] = 'openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass env:ARKHIVE_CRYPT_PASSWORD';
        }
        file_put_contents("{$outDir}/files.list", implode("\n", $list ?? ['srv']) . "\n");
        $script = 'set -o pipefail; cd ' . escapeshellarg($root) . ' && ' . implode(' | ', $stages) . ' > ' . escapeshellarg($archive);
        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['ARKHIVE_CRYPT_PASSWORD' => $password, 'PATH' => (string) getenv('PATH')]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start bash');
        }
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException("The 1.x pipeline failed: {$stderr}");
        }

        return $archive;
    }

    /**
     * Encrypts $data like 1.x, without tar or compression (for "not an archive" cases).
     */
    public static function encrypt(string $data, string $path, string $password = self::PASSWORD): void
    {
        $process = proc_open(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '100000', '-pass', 'env:ARKHIVE_CRYPT_PASSWORD', '-out', $path], [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes, null, ['ARKHIVE_CRYPT_PASSWORD' => $password, 'PATH' => (string) getenv('PATH')]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start openssl');
        }
        fwrite($pipes[0], $data);
        fclose($pipes[0]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('openssl failed');
        }
    }

    /**
     * A ustar archive with one member called $name (tar itself refuses to create "../x").
     */
    public static function rawTar(string $name, string $content): string
    {
        $header = str_pad($name, 100, "\0")
            . str_pad('0000644', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', strlen($content)), 12, "\0") . str_pad(sprintf('%011o', 0), 12, "\0")
            . '        ' . '0' . str_repeat("\0", 100) . "ustar\x0000" . str_repeat("\0", 247);
        $sum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);

        return $header . str_pad($content, (int) ceil(strlen($content) / 512) * 512, "\0") . str_repeat("\0", 1024);
    }

    /**
     * path => [type, mode, sha256 or link target], for a byte-identical comparison of two trees.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function manifest(string $root): array
    {
        $manifest = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            assert($item instanceof \SplFileInfo);
            $path = substr($item->getPathname(), strlen($root));
            $mode = sprintf('%04o', lstat($item->getPathname())['mode'] & 07777);
            $manifest[$path] = match (true) {
                $item->isLink() => ['link', '', (string) readlink($item->getPathname())],
                $item->isDir() => ['dir', $mode, ''],
                default => ['file', $mode, (string) hash_file('sha256', $item->getPathname())],
            };
        }
        ksort($manifest);

        return $manifest;
    }
}
