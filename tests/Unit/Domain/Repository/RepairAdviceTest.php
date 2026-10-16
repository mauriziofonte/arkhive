<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Repository;

use Mfonte\Arkhive\Domain\Repository\RepairAdvice;
use PHPUnit\Framework\TestCase;

final class RepairAdviceTest extends TestCase
{
    public function testMissingPacksFollowResticsTroubleshootingOrder(): void
    {
        self::assertSame(
            'Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then run, in this order: restic repair index, then arkhive backup (uploads again what is still on this host), then restic repair snapshots --forget, then arkhive maintain. ArkHive never repairs a repository automatically',
            RepairAdvice::hint([], true),
        );
    }

    public function testDamagedPacksAreRemovedFirstAndTheResticCommandLineIsGiven(): void
    {
        self::assertSame(
            "Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first; then run, in this order: restic repair packs aaaa bbbb, then restic repair index, then arkhive backup (uploads again what is still on this host), then restic repair snapshots --forget, then arkhive maintain (restic as: RESTIC_PASSWORD_FILE='/etc/p' restic -r '/srv/r'). ArkHive never repairs a repository automatically",
            RepairAdvice::hint(['aaaa', 'bbbb'], true, "RESTIC_PASSWORD_FILE='/etc/p' restic -r '/srv/r'"),
        );
        self::assertStringContainsString('then run, in this order: restic repair packs aaaa, then arkhive backup', RepairAdvice::hint(['aaaa']));
    }

    public function testUnknownDamagePointsAtTheGuide(): void
    {
        self::assertSame(
            'Stop and read https://restic.readthedocs.io/en/stable/077_troubleshooting.html first, and follow it for the errors restic check reports (arkhive maintain runs it); back up again afterwards. ArkHive never repairs a repository automatically',
            RepairAdvice::hint(),
        );
    }
}
