<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\MysqlConfig;
use Mfonte\Arkhive\Domain\Configuration\PgsqlConfig;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;

/**
 * Ready-made configurations for unit tests (placeholders only).
 */
final class Configs
{
    public static function make(?MysqlConfig $mysql = null, ?PgsqlConfig $pgsql = null, ?string $dumpDirectory = null): Config
    {
        return new Config(
            '/etc/arkhive/config',
            ['/srv/www'],
            new ResticSettings('/srv/restic-repo', '/etc/arkhive/restic-password', null, 'restic', 'web1.example.com', 'auto'),
            7,
            4,
            6,
            '5%',
            $dumpDirectory,
            [],
            [],
            $mysql,
            $pgsql,
            null,
            [],
        );
    }

    public static function mysql(): MysqlConfig
    {
        return new MysqlConfig('db.example.com', 3306, 'backup', 'mysql-password-placeholder', ['*']);
    }

    public static function pgsql(): PgsqlConfig
    {
        return new PgsqlConfig('pg.example.com', 5432, 'backup', 'pgsql-password-placeholder', ['crm'], ['--no-owner', '--no-privileges']);
    }
}
