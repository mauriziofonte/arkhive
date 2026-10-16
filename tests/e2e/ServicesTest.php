<?php

declare(strict_types=1);

namespace Tests\e2e;

use PHPUnit\Framework\Attributes\DataProvider;

final class ServicesTest extends E2eTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function services(): iterable
    {
        yield 'sftp' => ['E2E_SFTP_HOST', 'E2E_SFTP_PORT'];
        yield 'mariadb' => ['E2E_MYSQL_HOST', 'E2E_MYSQL_PORT'];
        yield 'postgres' => ['E2E_PGSQL_HOST', 'E2E_PGSQL_PORT'];
    }

    #[DataProvider('services')]
    public function testServiceAcceptsConnections(string $hostVar, string $portVar): void
    {
        $socket = @fsockopen(self::env($hostVar), (int) self::env($portVar), $errno, $error, 10.0);

        self::assertNotFalse($socket, "cannot connect: {$error}");
        fclose($socket);
    }

    public function testReleasesServerAnswers(): void
    {
        $body = @file_get_contents(self::env('E2E_RELEASES_URL') . '/');

        self::assertNotFalse($body);
    }

    public function testThePharRuns(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::env('E2E_PHAR')) . ' --version', $output, $status);

        self::assertSame(0, $status);
        self::assertStringStartsWith('ArkHive ', $output[0] ?? '');
    }
}
