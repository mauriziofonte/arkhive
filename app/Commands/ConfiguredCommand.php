<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Configuration\ConfigLoader;
use Mfonte\Arkhive\Application\Configuration\ConfigLocator;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Symfony\Component\Console\Input\InputOption;

/**
 * A command that works on the configuration: --config, or discovery in the documented order.
 */
abstract class ConfiguredCommand extends ArkhiveCommand
{
    public function __construct()
    {
        parent::__construct();

        $this->addOption('config', null, InputOption::VALUE_REQUIRED, 'Configuration file to use (skips discovery)');
    }

    protected function loadConfig(bool $checkSecrets = true): Config
    {
        $environment = $this->terminal->environment();
        $loader = new ConfigLoader(ConfigLocator::fromEnvironment($environment), self::hostname());
        $config = $loader->load(self::stringOption($this->option('config')), $checkSecrets);
        $this->log->debug("Configuration: {$config->source}");
        if ($config->unknownKeys !== []) {
            $this->log->warning("Unknown keys in {$config->source}, ignored: " . implode(', ', $config->unknownKeys));
        }

        return $config;
    }

    protected static function hostname(): string
    {
        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'localhost';
    }

    /**
     * Sends the notification if configured; a temporary SMTP error (4xx) or an unreachable server is
     * tried again. A failure is logged, never fatal: returns false (exit 3).
     */
    protected function sendNotification(Config $config, Notification $notification): bool
    {
        if ($config->notify === null || !$config->notify->wants($notification)) {
            return true;
        }
        try {
            // resolved here, not in the constructor: commands are built when the application boots
            $notifier = $this->laravel->make(Notifier::class);
            $notify = $config->notify;
            $this->laravel->make(Retrier::class)->run(
                static fn () => $notifier->send($notify, $notification),
                RetryPolicy::mail(),
                'Sending the notification',
                $this->log,
            );
            $this->log->debug('Notification sent to ' . implode(', ', $config->notify->to));

            return true;
        } catch (ArkhiveException $e) {
            $this->log->warning($e->getMessage());
            if ($e->hint() !== null) {
                $this->log->notice($e->hint());
            }

            return false;
        }
    }

    /**
     * The facts every notification starts with.
     *
     * @return array<string, string>
     */
    protected function commonFacts(Config $config): array
    {
        $version = config('app.version');

        return [
            'Host' => $config->restic->host,
            'Configuration' => $config->source,
            'Repository' => $config->restic->repository,
            'Paths' => implode(' ', $config->backupPaths),
            'Time' => $this->clock->now()->format(DATE_ATOM),
            'ArkHive' => is_string($version) ? $version : 'unknown',
        ];
    }
}
