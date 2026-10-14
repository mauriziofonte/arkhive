<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Systemd;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\ServiceUnits;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\Timeouts;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Schedule\UnitState;
use Mfonte\Arkhive\Domain\System\Dependency;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * `systemctl show` and `journalctl`, read in PHP (no pipeline, LC_ALL=C).
 */
final class Systemctl implements ServiceUnits
{
    private const PROPERTIES = ['Id', 'LoadState', 'Result', 'ExecMainCode', 'ExecMainStatus', 'InvocationID', 'ExecMainStartTimestamp', 'ExecMainExitTimestamp'];

    public function __construct(private readonly ProcessRunner $runner, private readonly Dependencies $dependencies)
    {
    }

    public function state(string $unit): UnitState
    {
        $systemctl = $this->dependencies->require(Dependency::Systemctl, 'to read the state of a systemd unit');
        $result = $this->runner->run((new Command([$systemctl, 'show', '--no-pager', '--property=' . implode(',', self::PROPERTIES), '--', $unit]))->withTimeout(Timeouts::VERSION));
        if (!$result->succeeded()) {
            throw new UnavailableException($result->failure("Reading the state of {$unit}"), hint: 'Is this host running systemd? systemctl must reach the service manager');
        }
        $properties = self::properties($result->stdout);
        if (($properties['LoadState'] ?? '') === 'not-found') {
            throw new NoInputException("systemd has no unit {$unit}", hint: 'Name the unit as systemctl list-units shows it, e.g. arkhive-backup.service');
        }

        return UnitState::fromProperties($unit, $properties);
    }

    public function journal(string $invocationId, int $lines): array
    {
        $journalctl = $this->dependencies->find(Dependency::Journalctl);
        if ($journalctl === null || preg_match('/^[0-9a-f]{32}$/', $invocationId) !== 1) {
            return [];
        }
        $result = $this->runner->run((new Command([$journalctl, '--no-pager', '--quiet', '--output=cat', '--lines=' . $lines, '_SYSTEMD_INVOCATION_ID=' . $invocationId]))->withTimeout(Timeouts::QUERY));
        if (!$result->succeeded()) {
            return [];
        }

        return array_values(array_filter(preg_split('/\R/', rtrim($result->stdout)) ?: [], static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, string>
     */
    public static function properties(string $output): array
    {
        $properties = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $equals = strpos($line, '=');
            if ($equals !== false && $equals > 0) {
                $properties[substr($line, 0, $equals)] = substr($line, $equals + 1);
            }
        }

        return $properties;
    }
}
