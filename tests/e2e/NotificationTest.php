<?php

declare(strict_types=1);

namespace Tests\e2e;

use RuntimeException;
use Tests\Support\CliResult;

/**
 * Notifications through a real SMTP conversation (tests/e2e/bin/fake-smtp.php): a temporary refusal
 * is tried again, a permanent one is not.
 */
final class NotificationTest extends E2eTestCase
{
    private string $dir;
    private string $repository;

    /** @var array{resource, array<int, resource>}|null */
    private ?array $smtp = null;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/site");
        file_put_contents("{$this->dir}/site/index.php", "<?php echo 'hello';\n");
        mkdir("{$this->dir}/locks");
        mkdir("{$this->dir}/spool");
        $this->repository = "{$this->dir}/repo";
        $this->arkhiveInit($this->dir, $this->repository, null);
    }

    protected function tearDown(): void
    {
        if ($this->smtp !== null) {
            proc_terminate($this->smtp[0]);
            proc_close($this->smtp[0]);
        }
    }

    /**
     * Starts the SMTP server; returns its port.
     */
    private function startSmtp(string $codes): int
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/bin/fake-smtp.php', "{$this->dir}/spool", $codes], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "{$this->dir}/smtp.log", 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start the SMTP server');
        }
        $this->smtp = [$process, $pipes];
        $port = (int) trim((string) fgets($pipes[1]));
        self::assertGreaterThan(0, $port, (string) @file_get_contents("{$this->dir}/smtp.log"));

        return $port;
    }

    private function backup(int $port): CliResult
    {
        $config = $this->writeConfig("{$this->dir}/config", [
            'BACKUP_PATHS' => "{$this->dir}/site",
            'RESTIC_REPOSITORY' => $this->repository,
            'RESTIC_PASSWORD_FILE' => self::env('E2E_PASSWORD_FILE'),
            'RESTIC_HOST' => 'e2e.example.com',
            'NOTIFY' => 'true',
            'SMTP_HOST' => '127.0.0.1',
            'SMTP_PORT' => (string) $port,
            'SMTP_ENCRYPTION' => 'none',
            'SMTP_FROM' => 'arkhive@example.com',
            'SMTP_TO' => 'admin@example.com',
        ]);

        return self::arkhive(['backup', '--config', $config], ['ARKHIVE_LOCK_DIR' => "{$this->dir}/locks"]);
    }

    /**
     * @return list<string>
     */
    private function delivered(): array
    {
        return glob("{$this->dir}/spool/*.eml") ?: [];
    }

    public function testAGreylistedNotificationIsDeliveredOnTheNextTry(): void
    {
        $port = $this->startSmtp('451');

        $result = $this->backup($port);

        self::assertSame(0, $result->exitCode, $result->output());
        self::assertMatchesRegularExpression('/warning Sending the notification: The notification e-mail could not be sent through 127\.0\.0\.1:\d+: .*451.*; trying again in 1\ds \(attempt 2 of 3\)/', $result->stderr);
        self::assertCount(1, $this->delivered());
        self::assertStringContainsString('Subject: [ArkHive] backup OK on e2e.example.com', (string) file_get_contents($this->delivered()[0]));
    }

    public function testARejectedNotificationIsNotTriedAgainAndTheRunIsExit3(): void
    {
        $port = $this->startSmtp('550');

        $result = $this->backup($port);

        self::assertSame(3, $result->exitCode, $result->output());
        self::assertStringContainsString('550', $result->stderr);
        self::assertStringNotContainsString('trying again', $result->stderr);
        self::assertSame([], $this->delivered());
    }
}
