<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Config\PasswordFile;
use Mfonte\Arkhive\Services\Legacy\LegacyArchive;
use Mfonte\Arkhive\Services\Legacy\LegacyExtractor;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\Restore\TargetDirectory;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Support\Bytes;

/**
 * spec §6 decrypt-legacy: opens a 1.x archive (`.enc.arbk`, `.arbk`, `.arbk.xz`, `.tar`). The
 * password comes from --password-file or ARKHIVE_CRYPT_PASSWORD, never from the main configuration.
 */
final class DecryptLegacyCommand extends ArkhiveCommand
{
    protected $signature = 'decrypt-legacy
        {file : The 1.x archive}
        {--to= : Extract into this new or empty directory (default: ./<archive name> without the suffixes)}
        {--password-file= : File holding the 1.x CRYPT_PASSWORD (mode 0600); default: env ARKHIVE_CRYPT_PASSWORD}
        {--json : Print the result as JSON on stdout}';

    protected $description = 'Decrypt and extract an ArkHive 1.x archive (gzip, xz or plain tar)';

    protected function perform(): int
    {
        $file = self::stringOption($this->argument('file'));
        if ($file === null) {
            throw new UsageException('Name the 1.x archive: arkhive decrypt-legacy <file> [--to <dir>]');
        }
        $cwd = (string) getcwd();
        $archive = LegacyArchive::open(TargetDirectory::absolute($file, $cwd));
        $to = self::stringOption($this->option('to'));
        $target = new TargetDirectory(TargetDirectory::absolute($to ?? $archive->baseName(), $cwd));
        $password = $archive->encrypted ? $this->password() : null;
        if (!$archive->encrypted) {
            $this->log->notice(basename($archive->path) . ' is not encrypted: extracting it as it is');
        }

        $extractor = new LegacyExtractor(
            $this->laravel->make(ProcessRunner::class),
            $this->laravel->make(Dependencies::class),
            $this->cleanup,
        );
        $report = $extractor->extract($archive, $target, $password, $this->log);

        if ((bool) $this->option('json')) {
            $this->result(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $this->log->success(sprintf(
            'Extracted %d entries (%s, %s%s) into %s',
            $report->entries,
            Bytes::format($report->bytes),
            $report->compression === 'none' ? 'tar' : $report->compression,
            $report->encrypted ? ', decrypted' : '',
            $report->target,
        ), $report->toArray());
        $this->log->notice('Files were extracted with the paths they had on the 1.x host, relative to ' . $report->target . '; database dumps are .sql files among them');

        return ExitCode::SUCCESS;
    }

    private function password(): string
    {
        $file = self::stringOption($this->option('password-file'));
        if ($file !== null) {
            PasswordFile::assertSafe($file, '--password-file');
            $password = rtrim((string) file_get_contents($file), "\r\n");
            if ($password === '') {
                throw new UsageException("--password-file {$file} holds an empty password");
            }

            return $password;
        }
        $env = $this->terminal->environment()[LegacyExtractor::PASSWORD_ENV] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        throw new UsageException(
            'The archive is encrypted and no password was given: pass --password-file <file> or set ' . LegacyExtractor::PASSWORD_ENV,
            'Put the CRYPT_PASSWORD of the 1.x configuration in a file (chmod 600) and pass it with --password-file',
        );
    }
}
