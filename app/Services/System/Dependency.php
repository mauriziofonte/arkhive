<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\System;

/**
 * External programs ArkHive runs, with the package that provides them on each distribution.
 */
enum Dependency: string
{
    case Restic = 'restic';
    case MysqlDump = 'mysql-dump';
    case MysqlClient = 'mysql-client';
    case PgDump = 'pg_dump';
    case PgClient = 'psql';
    case Ssh = 'ssh';
    case Openssl = 'openssl';
    case Xz = 'xz';
    case Tar = 'tar';
    case Fuse = 'fuse';

    /**
     * Binary names, preferred first.
     *
     * @return list<string>
     */
    public function binaries(): array
    {
        return match ($this) {
            self::Restic => ['restic'],
            self::MysqlDump => ['mariadb-dump', 'mysqldump'],
            self::MysqlClient => ['mariadb', 'mysql'],
            self::PgDump => ['pg_dump'],
            self::PgClient => ['psql'],
            self::Ssh => ['ssh'],
            self::Openssl => ['openssl'],
            self::Xz => ['xz'],
            self::Tar => ['tar'],
            self::Fuse => ['fusermount3', 'fusermount'],
        };
    }

    public function label(): string
    {
        return implode(' or ', $this->binaries());
    }

    /**
     * @return list<string>
     */
    public function packages(PackageManager $manager): array
    {
        return match ($this) {
            self::Restic => ['restic'],
            self::MysqlDump, self::MysqlClient => [match ($manager) {
                PackageManager::Dnf => 'mariadb',
                PackageManager::Pacman => 'mariadb-clients',
                default => 'mariadb-client',
            }],
            self::PgDump, self::PgClient => [match ($manager) {
                PackageManager::Apt, PackageManager::Apk => 'postgresql-client',
                default => 'postgresql',
            }],
            self::Ssh => [match ($manager) {
                PackageManager::Apt, PackageManager::Apk => 'openssh-client',
                PackageManager::Pacman => 'openssh',
                default => 'openssh-clients',
            }],
            self::Openssl => ['openssl'],
            self::Xz => [$manager === PackageManager::Apt ? 'xz-utils' : 'xz'],
            self::Tar => ['tar'],
            self::Fuse => ['fuse3'],
        };
    }
}
