<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Configuration;

/**
 * What restic needs to reach a repository. Comes from the config, or from --repo/--password-file
 * on a bare-metal restore with no config at all (spec §10.4).
 */
final readonly class ResticSettings
{
    public function __construct(
        public string $repository,
        public string $passwordFile,
        public ?string $sftpCommand = null,
        public string $binary = 'restic',
        public string $host = '',
        public string $compression = 'auto',
    ) {
    }
}
