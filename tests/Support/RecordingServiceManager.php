<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\ServiceManager;

/**
 * A service manager that keeps every message, as systemd would receive them.
 */
final class RecordingServiceManager implements ServiceManager
{
    /** @var list<array<string, string>> */
    public array $messages = [];

    public function __construct(public bool $listening = true, public ?float $watchdog = null)
    {
    }

    public function supervised(): bool
    {
        return $this->listening;
    }

    public function notify(array $state): bool
    {
        if (!$this->listening) {
            return false;
        }
        $this->messages[] = $state;

        return true;
    }

    public function watchdogInterval(): ?float
    {
        return $this->watchdog;
    }

    /**
     * @return list<string> every STATUS= sent, in order
     */
    public function statuses(): array
    {
        return array_values(array_filter(array_map(static fn (array $m): ?string => $m['STATUS'] ?? null, $this->messages), static fn (?string $s): bool => $s !== null));
    }
}
