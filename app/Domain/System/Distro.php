<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\System;

/**
 * The Linux distribution, as described by os-release(5) (ID, ID_LIKE, VERSION_ID, PRETTY_NAME).
 */
final readonly class Distro
{
    /**
     * @param list<string> $idLike
     */
    public function __construct(
        public string $id,
        public array $idLike = [],
        public string $versionId = '',
        public string $prettyName = '',
    ) {
    }

    public static function fromOsRelease(string $content): self
    {
        $values = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)=(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $values[$match[1]] = self::unquote(trim($match[2]));
        }
        $id = strtolower($values['ID'] ?? '');
        $like = preg_split('/\s+/', strtolower($values['ID_LIKE'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return new self(
            $id === '' ? 'unknown' : $id,
            $like,
            $values['VERSION_ID'] ?? '',
            $values['PRETTY_NAME'] ?? ($values['NAME'] ?? ''),
        );
    }

    /**
     * debian, ubuntu, el (RHEL and rebuilds), fedora, alpine, arch, suse or unknown; shared with the
     * startup check in bootstrap/requirements.php.
     */
    public function family(): string
    {
        return arkhive_os_family(['id' => $this->id, 'like' => $this->idLike, 'version' => $this->versionId]);
    }

    public function packageManager(): ?PackageManager
    {
        return match ($this->family()) {
            'debian', 'ubuntu' => PackageManager::Apt,
            'el', 'fedora' => PackageManager::Dnf,
            'alpine' => PackageManager::Apk,
            'arch' => PackageManager::Pacman,
            'suse' => PackageManager::Zypper,
            default => null,
        };
    }

    /**
     * RHEL and its rebuilds, where restic comes from EPEL.
     */
    public function isEnterpriseLinux(): bool
    {
        return $this->family() === 'el';
    }

    public function is(string $id): bool
    {
        return $this->id === $id || in_array($id, $this->idLike, true);
    }

    public function describe(): string
    {
        if ($this->prettyName !== '') {
            return $this->prettyName;
        }

        return trim($this->id . ' ' . $this->versionId);
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $inner = substr($value, 1, -1);

            return $value[0] === '"' ? (string) preg_replace('/\\\\([\\\\"$`])/', '$1', $inner) : $inner;
        }

        return $value;
    }
}
