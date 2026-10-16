<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Configuration;

use Mfonte\Arkhive\Domain\Notification\Notification;

final readonly class NotifyConfig
{
    /**
     * @param 'ssl'|'tls'|'none' $encryption
     * @param list<string>       $to
     */
    public function __construct(
        public bool $onlyOnFailure,
        public string $host,
        public int $port,
        public string $encryption,
        public ?string $user,
        public ?string $password,
        public string $from,
        public array $to,
    ) {
    }

    /**
     * NOTIFY_ON_FAILURE_ONLY keeps successes out of the mailbox.
     */
    public function wants(Notification $notification): bool
    {
        return !$this->onlyOnFailure || !$notification->success;
    }
}
