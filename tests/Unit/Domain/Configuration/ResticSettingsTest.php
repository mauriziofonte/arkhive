<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Configuration;

use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResticSettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function repositories(): iterable
    {
        yield 'absolute path' => ['/srv/restic-repo', '/srv/restic-repo'];
        yield 'local: prefix' => ['local:/srv/restic-repo', '/srv/restic-repo'];
        yield 'sftp' => ['sftp:backup-user@backup.example.com:/restic', null];
        yield 'rest server' => ['rest:https://backup.example.com/', null];
        yield 's3' => ['s3:s3.example.com/bucket', null];
        yield 'relative path' => ['restic-repo', null];
    }

    #[DataProvider('repositories')]
    public function testTheLocalPathOfARepository(string $repository, ?string $expected): void
    {
        self::assertSame($expected, (new ResticSettings($repository, '/etc/arkhive/restic-password'))->localPath());
    }
}
