<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Systemd;

use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\ServiceUnits;
use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Application\Process\ProcessResult;
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
    public function __construct(private readonly ProcessRunner $runner, private readonly Dependencies $dependencies)
    {
    }

    public function state(string $unit): UnitState
    {
        $properties = $this->properties([$unit], UnitState::PROPERTIES)[$unit];
        if (($properties['LoadState'] ?? '') === 'not-found') {
            throw new NoInputException("systemd has no unit {$unit}", hint: 'Name the unit as systemctl list-units shows it, e.g. arkhive-backup.service');
        }

        return UnitState::fromProperties($unit, $properties);
    }

    public function properties(array $units, array $names): array
    {
        $result = $this->systemctl(['show', '--no-pager', '--property=' . implode(',', $names), '--', ...$units], 'Reading the state of ' . implode(', ', $units));
        // one block per unit, in the order asked, separated by an empty line
        $blocks = preg_split('/\R\R/', trim($result->stdout)) ?: [];
        $properties = [];
        foreach ($units as $i => $unit) {
            $properties[$unit] = self::parse($blocks[$i] ?? '');
        }

        return $properties;
    }

    public function reload(): void
    {
        $this->systemctl(['daemon-reload'], 'Reloading the systemd units');
    }

    public function enableNow(array $units): void
    {
        $this->systemctl(['enable', '--now', '--', ...$units], 'Enabling ' . implode(', ', $units));
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
     * @param list<string> $args
     */
    private function systemctl(array $args, string $what): ProcessResult
    {
        $systemctl = $this->dependencies->require(Dependency::Systemctl, 'to work with systemd units');
        $result = $this->runner->run((new Command([$systemctl, ...$args]))->withTimeout(Timeouts::QUERY));
        if (!$result->succeeded()) {
            throw new UnavailableException($result->failure($what), hint: 'Is this host running systemd? systemctl must reach the service manager (as root to change units)');
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $output): array
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
