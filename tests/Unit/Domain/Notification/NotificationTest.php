<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification;

use Mfonte\Arkhive\Domain\Notification\Notification;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testTheBodyIsTheHeadlineTheFactsAndTheDetails(): void
    {
        $plain = new Notification(false, 's', 'The backup failed.', ['Host' => 'web1.example.com', 'Unit' => 'arkhive-backup.service']);
        self::assertSame("The backup failed.\n\nHost: web1.example.com\nUnit: arkhive-backup.service\n", $plain->body());

        $detailed = new Notification(false, 's', 'The backup failed.', ['Host' => 'web1.example.com'], ['Backing up /srv', 'restic: Fatal: unable to open repository']);
        self::assertSame("The backup failed.\n\nHost: web1.example.com\n\nBacking up /srv\nrestic: Fatal: unable to open repository\n", $detailed->body());
    }
}
