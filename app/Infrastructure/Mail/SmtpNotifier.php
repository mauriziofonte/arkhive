<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Mail;

use Closure;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Domain\Configuration\NotifyConfig;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * SMTP notifications through Symfony Mailer.
 */
final class SmtpNotifier implements Notifier
{
    /** @var Closure(string): TransportInterface */
    private Closure $transportFactory;

    /**
     * @param (Closure(string): TransportInterface)|null $transportFactory builds the transport from the DSN (tests replace it)
     */
    public function __construct(?Closure $transportFactory = null)
    {
        $this->transportFactory = $transportFactory ?? static fn (string $dsn): TransportInterface => Transport::fromDsn($dsn);
    }

    /**
     * The DSN, with user and password URL-encoded (1.x broke on "@", ":" and "/"): smtps:// for
     * implicit TLS, smtp:// with require_tls for STARTTLS, smtp:// without opportunistic TLS for none.
     */
    public static function dsn(NotifyConfig $config): string
    {
        $credentials = '';
        if ($config->user !== null && $config->user !== '') {
            $credentials = rawurlencode($config->user);
            if ($config->password !== null && $config->password !== '') {
                $credentials .= ':' . rawurlencode($config->password);
            }
            $credentials .= '@';
        }
        $host = str_contains($config->host, ':') && !str_starts_with($config->host, '[') ? "[{$config->host}]" : $config->host;
        [$scheme, $query] = match ($config->encryption) {
            'ssl' => ['smtps', ''],
            'tls' => ['smtp', '?require_tls=true'],
            default => ['smtp', '?auto_tls=false'],
        };

        return "{$scheme}://{$credentials}{$host}:{$config->port}{$query}";
    }

    /**
     * @throws TemporaryException   when the server answers 4xx (greylisting, rate limit): worth retrying
     * @throws UnavailableException when the server cannot be reached: worth retrying
     * @throws ArkhiveException     when the message is refused for good (the caller turns it into exit 3)
     */
    public function send(NotifyConfig $config, Notification $notification): void
    {
        $email = (new Email())
            ->from($config->from)
            ->to(...$config->to)
            ->subject($notification->subject)
            ->text($notification->body());
        try {
            (new Mailer(($this->transportFactory)(self::dsn($config))))->send($email);
        } catch (Throwable $e) {
            throw self::failure($config, $e);
        }
    }

    public static function failure(NotifyConfig $config, Throwable $e): ArkhiveException
    {
        $detail = $config->password === null || $config->password === '' ? $e->getMessage() : str_replace([$config->password, rawurlencode($config->password)], '***', $e->getMessage());
        $message = "The notification e-mail could not be sent through {$config->host}:{$config->port}: {$detail}";
        $hint = 'Check SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION, SMTP_USER and SMTP_PASSWORD';
        if ($e instanceof UnexpectedResponseException && $e->getCode() >= 400 && $e->getCode() < 500) {
            return new TemporaryException($message, 'The SMTP server refused the message for now (' . $e->getCode() . '); it usually accepts it later');
        }
        if ($e instanceof TransportException && !$e instanceof UnexpectedResponseException && self::unreachable($e->getMessage())) {
            return UnavailableException::network($message, $hint);
        }

        return new ArkhiveException($message, hint: $hint);
    }

    private static function unreachable(string $message): bool
    {
        foreach (['Connection could not be established', 'Connection to ', 'Unable to connect', 'timed out', 'Connection refused', 'Connection reset', 'getaddrinfo'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
