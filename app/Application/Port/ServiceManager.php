<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

/**
 * The service manager that started ArkHive, when it listens for state changes (systemd's notify
 * protocol). Every method is a no-op without one.
 */
interface ServiceManager
{
    /**
     * Whether a service manager listens (systemd set NOTIFY_SOCKET).
     */
    public function supervised(): bool;

    /**
     * Sends state changes ("STATUS" => "...", "READY" => "1"); never throws.
     *
     * @param array<string, string> $state
     *
     * @return bool whether the message was delivered
     */
    public function notify(array $state): bool;

    /**
     * Seconds between watchdog pings when the unit sets WatchdogSec= for this process, else null.
     */
    public function watchdogInterval(): ?float;
}
