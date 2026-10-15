<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Mail;

use Mfonte\Arkhive\Domain\Configuration\NotifyConfig;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport;
use Tests\Support\CapturingTransport;

final class SmtpNotifierTest extends TestCase
{
    private static function config(string $encryption = 'tls', ?string $user = 'arkhive@example.com', ?string $password = 'p@ss:w/rd%', bool $onlyOnFailure = false, string $host = 'smtp.example.com', int $port = 587): NotifyConfig
    {
        return new NotifyConfig($onlyOnFailure, $host, $port, $encryption, $user, $password, 'arkhive@example.com', ['admin@example.com', 'ops@example.com']);
    }

    /**
     * @return iterable<string, array{NotifyConfig, string}>
     */
    public static function dsns(): iterable
    {
        yield 'STARTTLS required' => [self::config(), 'smtp://arkhive%40example.com:p%40ss%3Aw%2Frd%25@smtp.example.com:587?require_tls=true'];
        yield 'implicit TLS' => [self::config('ssl', port: 465), 'smtps://arkhive%40example.com:p%40ss%3Aw%2Frd%25@smtp.example.com:465'];
        yield 'no encryption, no opportunistic TLS' => [self::config('none', null, null, port: 25), 'smtp://smtp.example.com:25?auto_tls=false'];
        yield 'user without password' => [self::config('none', 'relay', null, port: 25), 'smtp://relay@smtp.example.com:25?auto_tls=false'];
        yield 'empty strings are no credentials' => [self::config('tls', '', ''), 'smtp://smtp.example.com:587?require_tls=true'];
        yield 'IPv6 host' => [self::config('tls', null, null, host: '2001:db8::25'), 'smtp://[2001:db8::25]:587?require_tls=true'];
    }

    #[DataProvider('dsns')]
    public function testDsnEncodesCredentialsAndEncryption(NotifyConfig $config, string $dsn): void
    {
        self::assertSame($dsn, SmtpNotifier::dsn($config));
        Transport::fromDsn($dsn);
        $this->addToAssertionCount(1);
    }

    public function testTheRealFactoryParsesEveryDsn(): void
    {
        $transport = (string) Transport::fromDsn(SmtpNotifier::dsn(self::config()));

        self::assertStringContainsString('smtp.example.com:587', $transport);
    }

    public function testSendBuildsAPlainTextMessage(): void
    {
        $transport = new CapturingTransport();
        (new SmtpNotifier($transport->factory()))->send(self::config(), new Notification(true, '[ArkHive] backup OK on host.example.com', 'The backup completed successfully.', [
            'Host' => 'host.example.com',
            'Snapshot' => 'e8ecb7f4',
        ]));

        self::assertSame(['smtp://arkhive%40example.com:p%40ss%3Aw%2Frd%25@smtp.example.com:587?require_tls=true'], $transport->dsns);
        $email = $transport->sent[0];
        self::assertSame('[ArkHive] backup OK on host.example.com', $email->getSubject());
        self::assertSame('arkhive@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame(['admin@example.com', 'ops@example.com'], array_map(static fn ($a): string => $a->getAddress(), $email->getTo()));
        self::assertSame("The backup completed successfully.\n\nHost:     host.example.com\nSnapshot: e8ecb7f4\n", $email->getTextBody());
    }

    public function testDeliveryFailuresAreExplainedWithoutThePassword(): void
    {
        $transport = new CapturingTransport();
        $transport->failWith = 'Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed for p@ss:w/rd% (p%40ss%3Aw%2Frd%25)".';

        try {
            (new SmtpNotifier($transport->factory()))->send(self::config(), new Notification(false, 's', 'h', []));
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame('The notification e-mail could not be sent through smtp.example.com:587: Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed for *** (***)".', $e->getMessage());
            self::assertSame('Check SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION, SMTP_USER and SMTP_PASSWORD', $e->hint());
        }

        $transport->failWith = 'Connection could not be established with host "smtp.example.com:25"';
        $this->expectExceptionObject(new ArkhiveException('The notification e-mail could not be sent through smtp.example.com:25: Connection could not be established with host "smtp.example.com:25"'));
        (new SmtpNotifier($transport->factory()))->send(self::config('none', null, null, port: 25), new Notification(false, 's', 'h', []));
    }

    /**
     * @return iterable<string, array{\Throwable, class-string<ArkhiveException>, bool}>
     */
    public static function failures(): iterable
    {
        yield 'greylisted (451)' => [new UnexpectedResponseException('Expected response code "250" but got code "451", with message "451 4.7.1 Greylisted, try again later".', 451), TemporaryException::class, true];
        yield 'rate limited (421)' => [new UnexpectedResponseException('Expected response code "220" but got code "421".', 421), TemporaryException::class, true];
        yield 'mailbox unavailable (550)' => [new UnexpectedResponseException('Expected response code "250" but got code "550".', 550), ArkhiveException::class, false];
        yield 'authentication failed (535)' => [new UnexpectedResponseException('Expected response code "235" but got code "535".', 535), ArkhiveException::class, false];
        yield 'no code' => [new UnexpectedResponseException('Expected response code "250" but got empty code.', 0), ArkhiveException::class, false];
        yield 'connection refused' => [new TransportException('Connection could not be established with host "ssl://smtp.example.com:465": stream_socket_client(): Unable to connect to ssl://smtp.example.com:465 (Connection refused)'), UnavailableException::class, true];
        yield 'read timeout' => [new TransportException('Connection to "smtp.example.com:587" timed out.'), UnavailableException::class, true];
        yield 'connection closed' => [new TransportException('Connection to "smtp.example.com:587" has been closed unexpectedly.'), UnavailableException::class, true];
        yield 'TLS required but not offered' => [new TransportException('TLS required but neither TLS or STARTTLS are in use.'), ArkhiveException::class, false];
        yield 'not a transport error' => [new \RuntimeException('Connection refused'), ArkhiveException::class, false];
    }

    /**
     * @param class-string<ArkhiveException> $class
     */
    #[DataProvider('failures')]
    public function testTemporaryAndNetworkFailuresCanBeRetried(\Throwable $failure, string $class, bool $transient): void
    {
        $transport = new CapturingTransport();
        $transport->throw = [$failure];

        try {
            (new SmtpNotifier($transport->factory()))->send(self::config(), new Notification(false, 's', 'h', []));
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($transient, $e->transient());
            self::assertStringStartsWith('The notification e-mail could not be sent through smtp.example.com:587: ', $e->getMessage());
            self::assertNotNull($e->hint());
        }
    }

    public function testAGreylistingHintSaysTheServerWillAcceptLater(): void
    {
        $e = SmtpNotifier::failure(self::config(password: 'secret'), new UnexpectedResponseException('451 greylisted secret', 451));

        self::assertSame('The notification e-mail could not be sent through smtp.example.com:587: 451 greylisted ***', $e->getMessage());
        self::assertSame('The SMTP server refused the message for now (451); it usually accepts it later', $e->hint());
    }

    public function testBodyWithoutFacts(): void
    {
        self::assertSame("Only a headline.\n\n", (new Notification(false, 's', 'Only a headline.', []))->body());
    }

    public function testDefaultFactoryIsSymfonyTransport(): void
    {
        $transport = new CapturingTransport();
        $notifier = new SmtpNotifier();
        $reflection = new \ReflectionProperty(SmtpNotifier::class, 'transportFactory');
        $factory = $reflection->getValue($notifier);
        self::assertInstanceOf(\Closure::class, $factory);
        self::assertStringContainsString('smtp.example.com', (string) $factory('smtp://smtp.example.com:25?auto_tls=false'));
        self::assertSame([], $transport->sent);
    }
}
