<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Legacy;

use Mfonte\Arkhive\Application\Output\Logger;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Process\Cleanup;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Restore\TargetDirectory;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Legacy\LegacyReport;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Domain\System\Dependency;
use Mfonte\Arkhive\Domain\System\InstallHints;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * spec §6 decrypt-legacy: decrypts and extracts a 1.x archive into a new directory. No shell
 * pipeline: each stage writes a private temporary file next to the target and its exit status is
 * checked before the next one starts (openssl → gzip in PHP or xz → tar). The password reaches
 * openssl through the environment, never argv.
 */
final class LegacyExtractor
{
    /** the variable openssl reads the password from (`-pass env:...`), as in 1.x */
    public const PASSWORD_ENV = 'ARKHIVE_CRYPT_PASSWORD';

    /** exactly the 1.x cipher parameters */
    public const OPENSSL_ARGS = ['enc', '-d', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '100000'];

    private const CHUNK = 1048576;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Dependencies $dependencies,
        private readonly Cleanup $cleanup,
    ) {
    }

    /**
     * @throws ArkhiveException
     */
    public function extract(LegacyArchive $archive, TargetDirectory $target, ?string $password, Logger $log): LegacyReport
    {
        if (is_link($target->path) || ($target->exists() && !is_dir($target->path)) || !$target->isEmpty()) {
            throw new RefusedException("{$target->path} exists and is not an empty directory: decrypt-legacy extracts only into a new or empty one", 'Choose a new directory with --to');
        }
        if (!is_writable($target->existingAncestor())) {
            throw new PermissionException("Cannot extract into {$target->path}: {$target->existingAncestor()} is not writable by this user");
        }
        $tar = $this->dependencies->require(Dependency::Tar, 'to extract a 1.x archive');
        $openssl = null;
        if ($archive->encrypted) {
            if ($password === null || $password === '') {
                throw new \LogicException('An encrypted archive needs a password');
            }
            $openssl = $this->dependencies->require(Dependency::Openssl, 'to decrypt a 1.x archive');
        }
        // the decrypted copy is about as large as the archive; the extracted files are checked later
        $target->assertSpace($archive->size, 'the decrypted copy of the archive');

        $work = TargetDirectory::createPrivate($target->existingAncestor(), '.arkhive-legacy-', 'the decrypted archive');
        $workId = $this->cleanup->add('remove the temporary decrypted archive', static function () use ($work): void {
            TargetDirectory::removeTree($work);
        });
        $created = !$target->exists();
        $targetId = null;
        try {
            $stream = $archive->path;
            if ($openssl !== null && $password !== null) {
                $log->info('Decrypting ' . basename($archive->path) . ' (' . Bytes::format($archive->size) . ')');
                $stream = "{$work}/decrypted";
                $this->decrypt($openssl, $archive->path, $stream, $password);
            }
            $compression = LegacyArchive::compression($stream, $archive->encrypted);
            $tarFile = match ($compression) {
                'gzip' => $this->gunzip($stream, "{$work}/archive.tar", $log),
                'xz' => $this->unxz($stream, "{$work}/archive.tar", $log),
                default => $stream,
            };
            if ($tarFile !== $stream && $stream !== $archive->path) {
                @unlink($stream);
            }

            $tarSize = (int) filesize($tarFile);
            $entries = $this->entries($tar, $tarFile);
            $target->assertSpace($tarSize, 'the extracted files');

            if ($created) {
                if (!@mkdir($target->path, 0700, true) && !is_dir($target->path)) {
                    throw new RefusedException("Cannot create the directory {$target->path}");
                }
                $path = $target->path;
                $targetId = $this->cleanup->add('remove the partly extracted directory', static function () use ($path): void {
                    TargetDirectory::removeTree($path);
                });
            }
            $log->info(sprintf('Extracting %d entries (%s) into %s', count($entries), Bytes::format($tarSize), $target->path));
            $result = $this->runner->run(new Command([$tar, '-x', '-p', '-f', $tarFile, '-C', $target->path]));
            if (!$result->succeeded()) {
                throw new ArkhiveException($result->failure('Extracting the archive'), hint: 'The extracted files are incomplete: check the free space and the permissions of the target');
            }
        } finally {
            $this->cleanup->runOne($workId);
        }
        if ($targetId !== null) {
            $this->cleanup->forget($targetId);
        }

        return new LegacyReport($archive->path, $target->path, $archive->encrypted, $compression, count($entries), $tarSize);
    }

    private function decrypt(string $openssl, string $in, string $out, string $password): void
    {
        $result = $this->runner->run(new Command(
            [$openssl, ...self::OPENSSL_ARGS, '-in', $in, '-out', $out, '-pass', 'env:' . self::PASSWORD_ENV],
            [self::PASSWORD_ENV => $password],
        ));
        if ($result->succeeded()) {
            return;
        }
        $stderr = $result->stderr;
        if (preg_match('/(unknown|unrecognized) option.*pbkdf2|pbkdf2.*(unknown|not supported)/i', $stderr) === 1) {
            throw new UnavailableException($result->failure('Decrypting the archive'), hint: '1.x archives need OpenSSL 1.1.1 or later (-pbkdf2): run decrypt-legacy on a newer system');
        }
        if (stripos($stderr, 'bad decrypt') !== false || stripos($stderr, 'wrong final block length') !== false) {
            throw new PermissionException('Cannot decrypt the archive: the password is wrong (openssl: bad decrypt)', hint: 'Use the CRYPT_PASSWORD of the 1.x configuration that made this archive');
        }

        throw new ArkhiveException($result->failure('Decrypting the archive'));
    }

    /**
     * gzip in PHP with inflate_add(), which (unlike gzread) reports a CRC error and, through the
     * stream status, a truncated archive. Concatenated members are decompressed one after the other.
     */
    private function gunzip(string $in, string $out, Logger $log): string
    {
        if (!extension_loaded('zlib')) {
            throw new UnavailableException('The PHP extension zlib is needed to decompress a gzip 1.x archive', hint: InstallHints::forPhpExtensions(['zlib'], $this->dependencies->distro()));
        }
        $log->info('Decompressing (gzip)');
        $source = @fopen($in, 'rb');
        $target = @fopen($out, 'xb');
        if ($source === false || $target === false) {
            throw new RefusedException("Cannot decompress {$in} into {$out}");
        }
        try {
            $context = self::inflater();
            $consumed = 0;
            while (!feof($source)) {
                $chunk = (string) fread($source, self::CHUNK);
                while ($chunk !== '') {
                    error_clear_last();
                    $data = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
                    if ($data === false) {
                        throw new DataException('The gzip data of the archive is corrupt (' . self::lastError() . '): the archive is damaged');
                    }
                    self::write($target, $data, $out);
                    if (inflate_get_status($context) !== ZLIB_STREAM_END) {
                        $consumed += strlen($chunk);
                        $chunk = '';
                        continue;
                    }
                    // a member ended: whatever follows in this chunk is the next member
                    $used = inflate_get_read_len($context) - $consumed;
                    $chunk = substr($chunk, $used);
                    $context = self::inflater();
                    $consumed = 0;
                    if (strlen($chunk) >= 2 && !str_starts_with($chunk, "\x1f\x8b")) {
                        throw new DataException('The archive has unexpected data after the end of its gzip data: it is damaged');
                    }
                    if ($chunk === '' && !feof($source)) {
                        continue 2;
                    }
                    if ($chunk === '') {
                        return $out;
                    }
                }
            }
            if (inflate_get_read_len($context) !== 0 || $consumed !== 0) {
                throw new DataException('The gzip data of the archive ends too early: the archive is truncated');
            }

            return $out;
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private static function inflater(): \InflateContext
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new SoftwareException('Cannot start the gzip decompression');
        }

        return $context;
    }

    /**
     * @param resource $target
     */
    private static function write($target, string $data, string $path): void
    {
        if ($data !== '' && @fwrite($target, $data) !== strlen($data)) {
            throw new IoException("Cannot write {$path}: the filesystem is full or not writable");
        }
    }

    private static function lastError(): string
    {
        $error = error_get_last();

        return $error === null ? 'unknown error' : (string) preg_replace('/^inflate_add\(\): /', '', $error['message']);
    }

    private function unxz(string $in, string $out, Logger $log): string
    {
        $xz = $this->dependencies->require(Dependency::Xz, 'to decompress an xz 1.x archive');
        $log->info('Decompressing (xz)');
        $target = @fopen($out, 'xb');
        if ($target === false) {
            throw new RefusedException("Cannot create {$out}");
        }
        try {
            $result = $this->runner->run((new Command([$xz, '-d', '-c']))->withInputFile($in)->withOutput($target));
        } finally {
            fclose($target);
        }
        if (!$result->succeeded()) {
            throw new DataException($result->failure('Decompressing the archive'), hint: 'The archive is damaged or truncated');
        }

        return $out;
    }

    /**
     * The member names, refused when one would land outside the target ("..").
     *
     * @return list<string>
     */
    private function entries(string $tar, string $tarFile): array
    {
        $result = $this->runner->run(new Command([$tar, '-t', '-f', $tarFile]));
        if (!$result->succeeded()) {
            throw new DataException($result->failure('Reading the archive'), hint: 'The archive is damaged or truncated');
        }
        $entries = array_values(array_filter(explode("\n", $result->stdout), static fn (string $line): bool => $line !== ''));
        foreach ($entries as $entry) {
            if (in_array('..', explode('/', $entry), true)) {
                throw new DataException("The archive contains the path {$entry}, which would be written outside the target: not extracted");
            }
        }

        return $entries;
    }
}
