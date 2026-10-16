<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * What the installed restic can do, by version (docs/2.1-PLAN.md §3). ArkHive supports restic from
 * 0.11.0 (Debian 11) and falls back where an older release lacks a feature; the adapter asks these
 * questions instead of comparing version strings.
 */
final readonly class ResticCapabilities
{
    /** the restic of Debian 11 */
    public const MINIMUM = '0.11.0';

    /** repository format 2 (compression) */
    public const REPOSITORY_V2 = '0.14.0';

    /** older releases work, but `doctor` recommends the static binary of a current release */
    public const RECOMMENDED = '0.17.0';

    /** the release line `doctor` recommends, as published at https://github.com/restic/restic/releases */
    public const RECOMMENDED_LINE = '0.19.x';

    public function __construct(public ResticVersion $version)
    {
    }

    public static function of(string $version): self
    {
        return new self(ResticVersion::of($version));
    }

    public function supported(): bool
    {
        return $this->version->atLeast(self::MINIMUM);
    }

    /**
     * Repository format 2 and `--compression`: older releases write format 1 and cannot open a
     * format 2 repository at all.
     */
    public function compression(): bool
    {
        return $this->version->atLeast(self::REPOSITORY_V2);
    }

    /** `check --read-data-subset=5%` (before: only "n/N") */
    public function percentSubset(): bool
    {
        return $this->version->atLeast('0.12.0');
    }

    /** `check --read-data-subset=500M` */
    public function sizeSubset(): bool
    {
        return $this->version->atLeast(self::REPOSITORY_V2);
    }

    /** `init --json` */
    public function jsonInit(): bool
    {
        return $this->version->atLeast('0.15.0');
    }

    /** `restore --sparse` */
    public function sparseRestore(): bool
    {
        return $this->version->atLeast('0.15.0');
    }

    /** `restore --json` progress and summary */
    public function jsonRestore(): bool
    {
        return $this->version->atLeast('0.16.0');
    }

    /** `--retry-lock` */
    public function retryLock(): bool
    {
        return $this->version->atLeast('0.16.0');
    }

    /** `--option sftp.args`; before it, ArkHive passes a whole `sftp.command` */
    public function sftpArgs(): bool
    {
        return $this->version->atLeast('0.16.1');
    }

    /** dedicated exit codes 10 (no repository) and 11 (locked); 12 (wrong password) came with 0.17.1 */
    public function dedicatedExitCodes(): bool
    {
        return $this->version->atLeast('0.17.0');
    }

    /**
     * At least RECOMMENDED: dedicated exit codes, sftp.args, JSON restores, and faster, clearer
     * failures on a damaged repository than the restic of Debian 11/12 and Ubuntu 22.04.
     */
    public function recommended(): bool
    {
        return $this->version->atLeast(self::RECOMMENDED);
    }

    /** `check --json` summary */
    public function jsonCheck(): bool
    {
        return $this->version->atLeast('0.18.0');
    }

    /**
     * What this restic lacks that a newer one would give, in words for `doctor`.
     *
     * @return list<string>
     */
    public function limitations(): array
    {
        $limits = [];
        if (!$this->compression()) {
            $limits[] = 'no compression (repository format 1; format 2 needs restic ' . self::REPOSITORY_V2 . ' or newer)';
        }
        if (!$this->sparseRestore()) {
            $limits[] = 'restores write sparse files in full';
        }
        if (!$this->jsonRestore()) {
            $limits[] = 'no restore progress';
        }

        return $limits;
    }
}
