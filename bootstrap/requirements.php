<?php

declare(strict_types=1);

/*
 * Startup requirement checks, run by the entry script before the autoloader. This file must parse
 * and run on old PHP versions (it is what tells a PHP 7 or 8.1 host what to install), so it uses
 * no syntax newer than PHP 7.0 (strict_types is accepted since 7.0): no type declarations, typed properties, arrow functions, match, nullsafe or enums.
 */

if (!function_exists('arkhive_os_release')) {
    /**
     * ID and ID_LIKE of /etc/os-release content.
     *
     * @param string|false|null $content
     *
     * @return array{id: string, like: list<string>, version: string}
     */
    function arkhive_os_release($content)
    {
        $values = [];
        if (is_string($content)) {
            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                if (preg_match('/^\s*([A-Z0-9_]+)=(.*)$/', $line, $match) === 1) {
                    $values[$match[1]] = trim(trim($match[2]), '"\'');
                }
            }
        }
        $like = isset($values['ID_LIKE']) ? preg_split('/\s+/', strtolower($values['ID_LIKE']), -1, PREG_SPLIT_NO_EMPTY) : [];

        return [
            'id' => isset($values['ID']) ? strtolower($values['ID']) : 'unknown',
            'like' => is_array($like) ? $like : [],
            'version' => isset($values['VERSION_ID']) ? $values['VERSION_ID'] : '',
        ];
    }

    /**
     * The distribution family: debian, ubuntu, el (RHEL and rebuilds), fedora, alpine, arch, suse, or unknown.
     *
     * @param array{id: string, like: list<string>, version: string} $os
     *
     * @return string
     */
    function arkhive_os_family(array $os)
    {
        $ids = array_merge([$os['id']], $os['like']);
        foreach ($ids as $id) {
            if ($id === 'ubuntu' || $id === 'linuxmint') {
                return 'ubuntu';
            }
            if ($id === 'debian' || $id === 'raspbian') {
                return 'debian';
            }
            if ($id === 'fedora') {
                return 'fedora';
            }
            if (in_array($id, ['rhel', 'centos', 'rocky', 'almalinux', 'ol'], true)) {
                return 'el';
            }
            if ($id === 'alpine') {
                return 'alpine';
            }
            if (in_array($id, ['arch', 'archlinux', 'manjaro', 'endeavouros'], true)) {
                return 'arch';
            }
            if ($id === 'suse' || $id === 'sles' || strpos($id, 'opensuse') === 0) {
                return 'suse';
            }
        }

        return 'unknown';
    }

    /**
     * Null when $version is supported, otherwise the message to print (with the install hint for the distro).
     *
     * @param string            $version
     * @param string|false|null $osRelease
     *
     * @return string|null
     */
    function arkhive_php_version_problem($version, $osRelease)
    {
        if (version_compare($version, '8.3.0', '>=')) {
            return null;
        }
        $hints = [
            'debian' => 'Debian 12 does not ship it: install PHP 8.4 from https://packages.sury.org/php/, then run: apt install php8.4-cli php8.4-mbstring',
            'ubuntu' => 'Ubuntu before 24.04 does not ship it: run add-apt-repository ppa:ondrej/php, then: apt install php8.4-cli php8.4-mbstring',
            'el' => 'Run: dnf module install php:8.3 (AppStream), or install PHP 8.3+ from the Remi repository (https://rpms.remirepo.net)',
            'fedora' => 'Run: dnf install php-cli php-mbstring php-process',
            'alpine' => 'Run: apk add php83 php83-phar php83-mbstring php83-posix php83-pcntl php83-curl (Alpine 3.20+)',
            'arch' => 'Run: pacman -S php',
            'suse' => 'Run: zypper install php8 php8-phar php8-mbstring php8-posix php8-pcntl php8-curl',
            'unknown' => 'Install PHP 8.3 or newer with your distribution\'s package manager.',
        ];
        $family = arkhive_os_family(arkhive_os_release($osRelease));

        return 'ArkHive needs PHP 8.3 or newer; this is PHP ' . $version . ' (' . PHP_BINARY . ').' . PHP_EOL . $hints[$family];
    }

    /**
     * Package that provides a PHP extension on a distribution family.
     *
     * @param string $extension
     * @param string $family
     * @param string $phpVersion
     *
     * @return string|null
     */
    function arkhive_php_extension_package($extension, $family, $phpVersion)
    {
        $parts = explode('.', $phpVersion);
        $short = $parts[0] . '.' . (isset($parts[1]) ? $parts[1] : '0');
        switch ($family) {
            case 'debian':
            case 'ubuntu':
                return in_array($extension, ['mbstring', 'curl', 'xml', 'zip', 'intl'], true) ? 'php' . $short . '-' . $extension : 'php' . $short . '-cli';
            case 'el':
            case 'fedora':
                if ($extension === 'posix' || $extension === 'pcntl') {
                    return 'php-process';
                }
                if ($extension === 'phar') {
                    return 'php-cli';
                }

                return $extension === 'mbstring' ? 'php-mbstring' : 'php-common';
            case 'alpine':
                $prefix = 'php' . str_replace('.', '', $short);

                return $extension === 'json' ? $prefix : $prefix . '-' . $extension;
            case 'arch':
                return 'php';
            case 'suse':
                return $extension === 'json' ? 'php8' : 'php8-' . $extension;
        }

        return null;
    }

    /**
     * Null when nothing is missing, otherwise the message naming the extensions and the install command.
     *
     * @param list<string>      $missing
     * @param string            $phpVersion
     * @param string|false|null $osRelease
     *
     * @return string|null
     */
    function arkhive_extensions_problem(array $missing, $phpVersion, $osRelease)
    {
        if ($missing === []) {
            return null;
        }
        $family = arkhive_os_family(arkhive_os_release($osRelease));
        $commands = [
            'debian' => 'apt install',
            'ubuntu' => 'apt install',
            'el' => 'dnf install',
            'fedora' => 'dnf install',
            'alpine' => 'apk add',
            'arch' => 'pacman -S',
            'suse' => 'zypper install',
        ];
        $message = 'ArkHive needs the PHP extension' . (count($missing) > 1 ? 's ' : ' ') . implode(', ', $missing)
            . ', not loaded by ' . PHP_BINARY . '.' . PHP_EOL;
        if (!isset($commands[$family])) {
            return $message . 'Install ' . (count($missing) > 1 ? 'them' : 'it') . ' with your distribution\'s package manager.';
        }
        $packages = [];
        foreach ($missing as $extension) {
            $packages[] = arkhive_php_extension_package($extension, $family, $phpVersion);
        }
        $message .= 'Run: ' . $commands[$family] . ' ' . implode(' ', array_values(array_unique($packages)));
        if ($family === 'arch') {
            $message .= ' (and enable the extension in /etc/php/php.ini if it is commented out)';
        }

        return $message;
    }

    /**
     * Extensions every command needs: phar runs the PHAR, posix checks file ownership, pcntl handles
     * SIGINT/SIGTERM so cleanup runs; json and mbstring are used throughout.
     *
     * @return list<string>
     */
    function arkhive_required_extensions()
    {
        return ['phar', 'json', 'mbstring', 'posix', 'pcntl'];
    }

    /**
     * All startup problems for this PHP, or an empty list.
     *
     * @return list<string>
     */
    function arkhive_startup_problems()
    {
        $osRelease = is_readable('/etc/os-release') ? file_get_contents('/etc/os-release') : null;
        $version = arkhive_php_version_problem(PHP_VERSION, $osRelease);
        if ($version !== null) {
            return [$version];
        }
        $missing = [];
        foreach (arkhive_required_extensions() as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        $extensions = arkhive_extensions_problem($missing, PHP_VERSION, $osRelease);

        return $extensions === null ? [] : [$extensions];
    }
}
