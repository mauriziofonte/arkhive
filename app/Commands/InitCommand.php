<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Config\PasswordFile;
use Mfonte\Arkhive\Services\Restic\ResticFactory;

/**
 * spec §6 init: creates the repository of the configuration. A missing RESTIC_PASSWORD_FILE is
 * generated (random, 0600); an existing repository is left alone and only checked to open.
 */
final class InitCommand extends ConfiguredCommand
{
    /** random bytes in a generated password (base64: 43 characters) */
    public const PASSWORD_BYTES = 32;

    protected $signature = 'init
        {--json : Print the result as JSON on stdout}';

    protected $description = 'Create the restic repository (and a random password file if there is none)';

    protected function perform(): int
    {
        $config = $this->loadConfig(checkSecrets: false);
        $settings = $config->restic;
        $passwordFile = $settings->passwordFile;
        $generated = !file_exists($passwordFile) && !is_link($passwordFile);
        $removeId = null;
        if ($generated) {
            self::generatePassword($passwordFile);
            $removeId = $this->cleanup->add('remove the generated password file', static function () use ($passwordFile): void {
                @unlink($passwordFile);
            });
        } else {
            PasswordFile::assertSafe($passwordFile);
        }

        $restic = $this->laravel->make(ResticFactory::class)->create($settings, 'to create the repository');
        $version = $restic->requireSupportedVersion();
        $this->log->debug("Using {$restic->binary} (restic {$version})");
        $this->log->info("Creating the restic repository {$settings->repository}");
        $id = $restic->init();

        if ($id === null) {
            if ($generated) {
                // the new random password cannot open the existing repository: do not leave it behind
                throw new ConfigException(
                    "A restic repository already exists at {$settings->repository}, and RESTIC_PASSWORD_FILE {$passwordFile} did not exist",
                    hint: "Put that repository's password in {$passwordFile} (chmod 600); a new password cannot open it",
                );
            }
            $restic->checkReachable();
            $this->log->success("The repository {$settings->repository} already exists and the password opens it: nothing to do");
            $this->json($settings->repository, null, false, false);

            return ExitCode::SUCCESS;
        }

        if ($removeId !== null) {
            $this->cleanup->forget($removeId);
            $this->log->notice("Generated a random repository password in {$passwordFile} (mode 0600)");
        }
        $this->log->success("Created the restic repository {$settings->repository}" . ($id === '' ? '' : ' (id ' . substr($id, 0, 8) . ')'), ['id' => $id]);
        $this->log->notice("Keep a second copy of the password in {$passwordFile} off this host (a password manager, a safe): without it nobody can restore these backups, and losing this server loses the file too");
        $this->json($settings->repository, $id, true, $generated);

        return ExitCode::SUCCESS;
    }

    private function json(string $repository, ?string $id, bool $created, bool $generated): void
    {
        if ((bool) $this->option('json')) {
            $this->result(json_encode(['repository' => $repository, 'id' => $id, 'created' => $created, 'password_generated' => $generated], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * A random password, written to a new 0600 file (never readable by others, not even briefly).
     */
    public static function generatePassword(string $path): void
    {
        if (!is_dir(dirname($path))) {
            throw new NoInputException("RESTIC_PASSWORD_FILE {$path} does not exist and neither does its directory", 'Create the directory (mkdir -p ' . dirname($path) . '), then run arkhive init again');
        }
        $password = rtrim(strtr(base64_encode(random_bytes(self::PASSWORD_BYTES)), '+/', '-_'), '=');
        $umask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RefusedException("Cannot create the password file {$path}", hint: 'Check that its directory is writable by this user');
        }
        $written = fwrite($handle, $password . "\n");
        fclose($handle);
        chmod($path, 0600);
        if ($written !== strlen($password) + 1) {
            @unlink($path);

            throw new IoException("Cannot write the password file {$path}");
        }
    }
}
