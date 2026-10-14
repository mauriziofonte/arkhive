<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Configuration\PasswordFile;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Exceptions\UsageException;
use Symfony\Component\Console\Input\InputOption;

/**
 * A command that reads the repository: from the configuration, or on a host with no ArkHive
 * configuration at all from --repo and --password-file (bare-metal recovery, spec §10.4).
 */
abstract class RepositoryCommand extends ConfiguredCommand
{
    public function __construct()
    {
        parent::__construct();

        $this->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Repository to use without a configuration file (bare-metal recovery)');
        $this->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'Repository password file, with --repo');
        $this->addOption('sftp-command', null, InputOption::VALUE_REQUIRED, 'SSH command for an sftp: repository, with --repo (e.g. "ssh -p 2222 -i KEY user@host -s sftp")');
    }

    /**
     * @return array{ResticSettings, Config|null} the configuration only when one is in use
     */
    protected function repositorySettings(): array
    {
        $repository = self::stringOption($this->option('repo'));
        if ($repository === null) {
            foreach (['password-file', 'sftp-command'] as $flag) {
                if (self::stringOption($this->option($flag)) !== null) {
                    throw new UsageException("--{$flag} goes with --repo: without --repo the repository comes from the configuration");
                }
            }
            $config = $this->loadConfig();

            return [$config->restic, $config];
        }

        $passwordFile = self::stringOption($this->option('password-file'));
        if ($passwordFile === null) {
            throw new UsageException('--repo needs --password-file: the file holding the repository password', 'Write the password to a file only you can read (chmod 600) and pass it with --password-file');
        }
        $absolute = str_starts_with($passwordFile, '/') ? $passwordFile : (getcwd() ?: '.') . '/' . $passwordFile;
        PasswordFile::assertSafe($absolute, '--password-file');
        $this->log->debug("Using the repository {$repository} from --repo, without a configuration file");
        // a configuration given explicitly still provides the database connections (--import-as)
        $config = self::stringOption($this->option('config')) === null ? null : $this->loadConfig();

        return [new ResticSettings($repository, $absolute, self::stringOption($this->option('sftp-command')), host: self::hostname()), $config];
    }

    /**
     * restic found and recent enough, repository reachable and password valid.
     */
    protected function openRepository(ResticSettings $settings, string $purpose): SnapshotStore
    {
        $restic = $this->laravel->make(SnapshotStoreFactory::class)->create($settings, $purpose);
        $version = $restic->requireSupportedVersion();
        $this->log->debug("Using {$restic->binary()} (restic {$version})");
        $this->log->info("Opening the repository {$settings->repository}");
        $restic->checkReachable();

        return $restic;
    }

    protected function hostOption(): ?string
    {
        return self::stringOption($this->option('host'));
    }
}
