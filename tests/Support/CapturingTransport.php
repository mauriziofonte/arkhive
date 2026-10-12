<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that keeps what it is given, or fails like an SMTP server would.
 */
final class CapturingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    /** @var list<string> */
    public array $dsns = [];

    public ?string $failWith = null;

    public function factory(): \Closure
    {
        return function (string $dsn): TransportInterface {
            $this->dsns[] = $dsn;

            return $this;
        };
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failWith !== null) {
            throw new TransportException($this->failWith);
        }
        assert($message instanceof Email);
        $this->sent[] = $message;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'capturing://';
    }
}
