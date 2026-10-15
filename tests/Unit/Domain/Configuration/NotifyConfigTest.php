<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Configuration;

use Mfonte\Arkhive\Domain\Configuration\NotifyConfig;
use Mfonte\Arkhive\Domain\Notification\Notification;
use PHPUnit\Framework\TestCase;

final class NotifyConfigTest extends TestCase
{
    public function testFailureOnlyModeSkipsSuccesses(): void
    {
        $ok = new Notification(true, 's', 'h', []);
        $failed = new Notification(false, 's', 'h', []);

        self::assertTrue(self::config(false)->wants($ok));
        self::assertTrue(self::config(false)->wants($failed));
        self::assertFalse(self::config(true)->wants($ok));
        self::assertTrue(self::config(true)->wants($failed));
    }

    private static function config(bool $onlyOnFailure): NotifyConfig
    {
        return new NotifyConfig($onlyOnFailure, 'smtp.example.com', 587, 'tls', null, null, 'arkhive@example.com', ['admin@example.com']);
    }
}
