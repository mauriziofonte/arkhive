<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\System;

enum PackageManager: string
{
    case Apt = 'apt';
    case Dnf = 'dnf';
    case Apk = 'apk';
    case Pacman = 'pacman';
    case Zypper = 'zypper';

    /**
     * @param list<string> $packages
     */
    public function install(array $packages): string
    {
        $verb = match ($this) {
            self::Apt => 'apt install',
            self::Dnf => 'dnf install',
            self::Apk => 'apk add',
            self::Pacman => 'pacman -S',
            self::Zypper => 'zypper install',
        };

        return $verb . ' ' . implode(' ', $packages);
    }
}
