<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Execution;

use Mfonte\Arkhive\Domain\Execution\MessageId;
use PHPUnit\Framework\TestCase;

final class MessageIdTest extends TestCase
{
    public function testTheEventsOfARun(): void
    {
        self::assertSame(MessageId::BackupStarted, MessageId::forRun('backup', null));
        self::assertSame(MessageId::BackupSucceeded, MessageId::forRun('backup', 0));
        self::assertSame(MessageId::BackupSucceeded, MessageId::forRun('backup', 3), 'warnings are a success');
        self::assertSame(MessageId::BackupFailed, MessageId::forRun('backup', 69));
        self::assertSame(MessageId::BackupFailed, MessageId::forRun('backup', 143));
        self::assertSame(MessageId::MaintainFailed, MessageId::forRun('maintain', 65));
        self::assertSame(MessageId::DrillSucceeded, MessageId::forRun('drill', 0));
        self::assertSame(MessageId::RestoreStarted, MessageId::forRun('restore', null));
        self::assertNull(MessageId::forRun('status', 0), 'only runs have events');
        self::assertNull(MessageId::forRun('ls', null));
    }

    public function testIdsAreUnique128BitValues(): void
    {
        $ids = array_map(static fn (MessageId $id): string => $id->value, MessageId::cases());

        self::assertCount(12, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        }
    }

    public function testSubjectsAndCatalog(): void
    {
        self::assertSame('backup', MessageId::BackupFailed->command());
        self::assertSame('ArkHive backup failed', MessageId::BackupFailed->subject());
        self::assertSame('ArkHive maintenance started', MessageId::MaintainStarted->subject());
        self::assertSame('ArkHive restore drill succeeded', MessageId::DrillSucceeded->subject());

        $catalog = MessageId::catalog();
        self::assertStringStartsWith("# ArkHive message catalog: install as /usr/lib/systemd/catalog/arkhive.catalog,\n", $catalog);
        self::assertSame(12, substr_count($catalog, "\n-- "));
        self::assertStringContainsString("\n-- 0dca6b902c5ac3d8b27d93e0ffcc4c5e\nSubject: ArkHive backup failed\nDefined-By: arkhive\nDocumentation: https://github.com/mauriziofonte/arkhive#logging\n\n", $catalog);
        self::assertStringContainsString('failed with exit status @ARKHIVE_EXIT_STATUS@.', $catalog);
        self::assertStringContainsString('journalctl ARKHIVE_RUN_ID=@ARKHIVE_RUN_ID@', $catalog);
    }
}
