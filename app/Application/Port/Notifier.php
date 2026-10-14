<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Domain\Configuration\NotifyConfig;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * Delivers the outcome of a run to the people who look after the host.
 */
interface Notifier
{
    /**
     * @throws ArkhiveException when the message could not be delivered (the caller turns it into exit 3)
     */
    public function send(NotifyConfig $config, Notification $notification): void;
}
