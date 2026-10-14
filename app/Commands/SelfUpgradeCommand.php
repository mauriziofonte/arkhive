<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

/**
 * The 1.x name, kept hidden (spec §6): prints a deprecation notice, then runs self-update.
 */
final class SelfUpgradeCommand extends SelfUpdateCommand
{
    protected $signature = 'self-upgrade' . self::OPTIONS;

    protected $description = 'Deprecated: use self-update';

    protected $hidden = true;

    protected function perform(): int
    {
        $this->log->warning('self-upgrade is deprecated and will be removed: use arkhive self-update');

        return parent::perform();
    }
}
