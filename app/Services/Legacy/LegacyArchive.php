<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Legacy;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * A 1.x archive file: `{date}-{user}-arkhive[.enc]{.arbk|.arbk.xz|.tar}`, a tar stream, optionally
 * gzip or xz compressed, optionally encrypted with `openssl enc -aes-256-cbc -salt -pbkdf2`.
 * The content decides, not the name: openssl's "Salted__" header, then the gzip, xz or tar magic.
 */
final class LegacyArchive
{
    /** what `openssl enc -salt` writes first */
    public const OPENSSL_MAGIC = 'Salted__';

    private function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly bool $encrypted,
    ) {
    }

    /**
     * @throws UsageException not a readable file, or empty
     */
    public static function open(string $path): self
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            throw new UsageException("The archive {$path} " . (file_exists($path) ? 'is not a regular file' : 'does not exist'));
        }
        if (!is_readable($path)) {
            throw new UsageException("The archive {$path} is not readable by this user");
        }
        $size = (int) filesize($path);
        if ($size === 0) {
            throw new UsageException("The archive {$path} is empty");
        }

        return new self($path, $size, self::head($path, strlen(self::OPENSSL_MAGIC)) === self::OPENSSL_MAGIC);
    }

    /**
     * The archive name without the 1.x suffixes: the default extraction directory.
     */
    public function baseName(): string
    {
        $name = basename($this->path);
        $stripped = (string) preg_replace('/(\.enc)?(\.arbk\.xz|\.arbk|\.tar\.gz|\.tar\.xz|\.tgz|\.tar)$/', '', $name);
        if ($stripped === '' || $stripped === $name) {
            return $name . '.extracted';
        }

        return $stripped;
    }

    /**
     * gzip, xz or none (a plain tar) from the first bytes of $path (the decrypted stream).
     *
     * @throws ArkhiveException neither: a wrong password that happened to pass openssl's padding check, or not a 1.x archive
     */
    public static function compression(string $path, bool $decrypted): string
    {
        $head = self::head($path, 512);
        if (str_starts_with($head, "\x1f\x8b")) {
            return 'gzip';
        }
        if (str_starts_with($head, "\xfd7zXZ\x00")) {
            return 'xz';
        }
        if (strlen($head) === 512 && substr($head, 257, 5) === 'ustar') {
            return 'none';
        }

        throw new ArkhiveException(
            $decrypted
                ? 'The decrypted data is neither gzip, xz nor tar: the password is wrong, or this is not an ArkHive 1.x archive'
                : 'The archive is neither encrypted nor gzip, xz or tar: this is not an ArkHive 1.x archive',
            hint: $decrypted ? 'Check the CRYPT_PASSWORD of the 1.x configuration that made this archive' : null,
        );
    }

    /**
     * @param positive-int $bytes
     */
    private static function head(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new UsageException("Cannot open {$path}");
        }
        $head = (string) fread($handle, $bytes);
        fclose($handle);

        return $head;
    }
}
