<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Configuration;

/**
 * What restic needs to reach a repository. Comes from the config, or from --repo/--password-file
 * on a bare-metal restore with no config at all (spec §10.4).
 */
final readonly class ResticSettings
{
    /** seconds to wait for a repository locked by another run (LOCK_WAIT) */
    public const DEFAULT_LOCK_WAIT = 300;

    /**
     * @param int $lockWait seconds to wait for a lock held by another run; 0 fails at once
     */
    public function __construct(
        public string $repository,
        public string $passwordFile,
        public ?string $sftpCommand = null,
        public string $binary = 'restic',
        public string $host = '',
        public string $compression = 'auto',
        public int $lockWait = self::DEFAULT_LOCK_WAIT,
    ) {
    }

    /**
     * The directory of a repository on a local filesystem ("/srv/restic", "local:/srv/restic"),
     * null for a remote one.
     */
    public function localPath(): ?string
    {
        $path = str_starts_with($this->repository, 'local:') ? substr($this->repository, 6) : $this->repository;

        return str_starts_with($path, '/') ? $path : null;
    }
}
