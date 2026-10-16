<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Configuration;

use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\NoInputException;

/**
 * Finds the configuration file. --config bypasses discovery; otherwise the first readable file of
 * candidates() wins. "./" is the real working directory (1.x used the directory of the executable).
 */
final class ConfigLocator
{
    public function __construct(
        private readonly string $cwd,
        private readonly string $home,
        private readonly ?string $xdgConfigHome = null,
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $cwd = getcwd();
        $home = $env['HOME'] ?? '';
        if ($home === '' && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = is_array($user) ? (string) $user['dir'] : '';
        }
        $xdg = $env['XDG_CONFIG_HOME'] ?? '';

        return new self($cwd === false ? '.' : $cwd, $home, $xdg === '' ? null : $xdg);
    }

    /**
     * The discovery order, documented in the README.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        $cwd = rtrim($this->cwd, '/');
        $list = [
            "{$cwd}/.arkhive-config",
            "{$cwd}/.config/arkhive-config",
            "{$cwd}/.config/arkhive/config",
        ];
        if ($this->xdgConfigHome !== null && str_starts_with($this->xdgConfigHome, '/')) {
            $list[] = rtrim($this->xdgConfigHome, '/') . '/arkhive/config';
        }
        if ($this->home !== '') {
            $home = rtrim($this->home, '/');
            $list[] = "{$home}/.arkhive-config";
            $list[] = "{$home}/.config/arkhive-config";
            $list[] = "{$home}/.config/arkhive/config";
        }
        $list[] = '/etc/arkhive-config';
        $list[] = '/etc/arkhive/config';

        return array_values(array_unique($list));
    }

    public function locate(?string $explicit = null): string
    {
        if ($explicit !== null) {
            if (!is_file($explicit) || !is_readable($explicit)) {
                throw new NoInputException("Configuration file {$explicit} (from --config) does not exist or is not readable");
            }

            return $explicit;
        }

        $candidates = $this->candidates();
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new ConfigException(
            "No ArkHive configuration file found. Looked for, in this order:\n  " . implode("\n  ", $candidates),
            'Create one from .arkhive-config.example, or pass --config <path>',
        );
    }
}
