<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * What to do about a damaged repository, in the order of restic's troubleshooting guide: remove the
 * damaged packs, rebuild the index, back up again (which uploads data still on the host and so can
 * heal older snapshots), drop what cannot be recovered from the snapshots, check again. ArkHive never
 * repairs a repository on its own (spec §10.5): it names the commands, the operator runs them.
 */
final class RepairAdvice
{
    public const TROUBLESHOOTING_URL = 'https://restic.readthedocs.io/en/stable/077_troubleshooting.html';

    /**
     * @param list<string> $brokenPacks  damaged pack files (check found them unreadable or wrong)
     * @param bool         $missingPacks pack files the index lists are gone from the repository
     * @param string|null  $restic       how to run restic on this repository (ResticSettings::commandLine())
     */
    public static function hint(array $brokenPacks = [], bool $missingPacks = false, ?string $restic = null): string
    {
        if ($brokenPacks === [] && !$missingPacks) {
            return 'Stop and read ' . self::TROUBLESHOOTING_URL . ' first, and follow it for the errors restic check reports (arkhive maintain runs it)'
                . '; back up again afterwards. ArkHive never repairs a repository automatically';
        }
        $steps = [];
        if ($brokenPacks !== []) {
            $steps[] = 'restic repair packs ' . implode(' ', $brokenPacks);
        }
        if ($missingPacks) {
            $steps[] = 'restic repair index';
        }
        array_push($steps, 'arkhive backup (uploads again what is still on this host)', 'restic repair snapshots --forget', 'arkhive maintain');

        return 'Stop and read ' . self::TROUBLESHOOTING_URL . ' first; then run, in this order: ' . implode(', then ', $steps)
            . ($restic === null ? '' : " (restic as: {$restic})")
            . '. ArkHive never repairs a repository automatically';
    }
}
