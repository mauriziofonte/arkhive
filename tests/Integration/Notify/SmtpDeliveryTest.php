<?php

declare(strict_types=1);

namespace Tests\Integration\Notify;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Services\Config\NotifyConfig;
use Mfonte\Arkhive\Services\Notify\Notification;
use Mfonte\Arkhive\Services\Notify\Notifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * spec §6.3 over a real SMTP conversation (tests/Fixtures/smtp/sink.php on 127.0.0.1): the DSN with
 * a user and password full of URL-special characters authenticates, the message arrives with its
 * facts, an unauthenticated relay works, a refused login names the server without the password, and
 * SMTP_ENCRYPTION=tls never falls back to plain text.
 */
final class SmtpDeliveryTest extends TestCase
{
    use TempDir;

    private const PASSWORD = 'p@ss:w/rd?#%&+ "x"';

    private string $dir;

    /** @var resource|null */
    private $sink = null;

    private int $port;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/mail");
    }

    protected function tearDown(): void
    {
        if (is_resource($this->sink)) {
            proc_terminate($this->sink);
            proc_close($this->sink);
        }
    }

    public function testAnAuthenticatedMessageArrivesWithItsFacts(): void
    {
        $this->startSink();

        (new Notifier())->send($this->config('none', 'backup@example.com', self::PASSWORD), self::notification());

        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertSame(['user' => 'backup@example.com', 'password' => self::PASSWORD], $messages[0]['auth']);
        self::assertSame('FROM:<arkhive@example.com>', $messages[0]['from']);
        self::assertSame(['TO:<admin@example.com>', 'TO:<ops@example.com>'], $messages[0]['to']);
        self::assertStringContainsString('Subject: [ArkHive] web1.example.com: backup OK', $messages[0]['data']);
        self::assertStringContainsString('Snapshot: e82340a7', $messages[0]['data']);
    }

    public function testAnUnauthenticatedRelay(): void
    {
        $this->startSink();

        (new Notifier())->send($this->config('none', null, null), self::notification());

        self::assertNull($this->messages()[0]['auth']);
    }

    public function testARefusedLoginNamesTheServerAndHidesThePassword(): void
    {
        $this->startSink('reject-auth');

        try {
            (new Notifier())->send($this->config('none', 'backup@example.com', self::PASSWORD), self::notification());
            self::fail('the refused login was not reported');
        } catch (ArkhiveException $e) {
            self::assertStringStartsWith("The notification e-mail could not be sent through 127.0.0.1:{$this->port}: ", $e->getMessage());
            self::assertStringContainsString('535', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            self::assertStringNotContainsString(rawurlencode(self::PASSWORD), $e->getMessage());
        }
        self::assertSame([], $this->messages());
    }

    public function testTlsRequiredNeverFallsBackToPlainText(): void
    {
        $this->startSink();

        try {
            (new Notifier())->send($this->config('tls', 'backup@example.com', self::PASSWORD), self::notification());
            self::fail('the message went out without TLS');
        } catch (ArkhiveException $e) {
            self::assertStringContainsString('TLS', $e->getMessage());
        }
        self::assertSame([], $this->messages(), 'nothing sent in clear text');
    }

    private function startSink(string $mode = ''): void
    {
        $portFile = "{$this->dir}/port";
        $sink = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/smtp/sink.php', $portFile, "{$this->dir}/mail", $mode], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$this->dir}/sink.err", 'w']], $pipes);
        self::assertIsResource($sink);
        $this->sink = $sink;
        $deadline = microtime(true) + 10;
        while (!is_file($portFile) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        self::assertFileExists($portFile, (string) @file_get_contents("{$this->dir}/sink.err"));
        $this->port = (int) file_get_contents($portFile);
    }

    private function config(string $encryption, ?string $user, ?string $password): NotifyConfig
    {
        return new NotifyConfig(false, '127.0.0.1', $this->port, $encryption, $user, $password, 'arkhive@example.com', ['admin@example.com', 'ops@example.com']);
    }

    private static function notification(): Notification
    {
        return new Notification(true, '[ArkHive] web1.example.com: backup OK', 'Backup completed', ['Host' => 'web1.example.com', 'Snapshot' => 'e82340a7']);
    }

    /**
     * @return list<array{auth: array{user: string, password: string}|null, from: string, to: list<string>, data: string}>
     */
    private function messages(): array
    {
        $messages = [];
        foreach (glob("{$this->dir}/mail/*.json") ?: [] as $file) {
            $messages[] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }

        return $messages;
    }
}
