<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use DateTimeImmutable;
use Mfonte\Arkhive\Application\Configuration\ConfigLoader;
use Mfonte\Arkhive\Application\Configuration\ConfigLocator;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Resilience\Retrier;
use Mfonte\Arkhive\Application\Run\RunJournal;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Notification\Notification;
use Mfonte\Arkhive\Domain\Repository\RepositoryKey;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\Domain\Shared\RetryPolicy;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\ExitCode;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * A command that works on the configuration: --config, or discovery in the documented order.
 */
abstract class ConfiguredCommand extends ArkhiveCommand
{
    /** what a recorded job (see recordedJob()) reports besides its exit code */
    protected ?string $runSnapshot = null;

    /** @var list<string> */
    protected array $runWarnings = [];

    /** the repository URL and the host a recorded job works on, once known (the run state is kept per repository) */
    protected ?string $runRepository = null;

    protected ?string $runHost = null;

    /** whether this run sent a notification (the OnFailure unit then does not send another) */
    private bool $runNotified = false;

    /** the snapshot of the run that just ended, for the event closing it */
    private ?string $endedSnapshot = null;

    /** why the last notification could not be sent */
    protected ?ArkhiveException $notificationFailure = null;

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
        $this->configureLogging($config->logTarget, $config->syslogFacility);
        $this->log->debug("Configuration: {$config->source}");
        $this->runRepository = $config->restic->repository;
        $this->runHost = $config->restic->host;
        foreach ($config->warnings as $warning) {
            $this->log->warning($warning);
        }
        if ($config->unknownKeys !== []) {
            $this->log->warning("Unknown keys in {$config->source}, ignored: " . implode(', ', $config->unknownKeys));
        }

        return $config;
    }

    /**
     * The job whose outcome is recorded in the state directory (backup, maintain, drill), or null.
     */
    protected function recordedJob(): ?string
    {
        return null;
    }

    /**
     * Records the outcome of a scheduled job. A usage error is not a run of the job.
     */
    #[\Override]
    protected function finished(int $exit, ?Throwable $failure, DateTimeImmutable $started): void
    {
        $job = $this->recordedJob();
        // a command object serves every call in the process: nothing of this run may leak into the next
        [$snapshot, $warnings, $notified, $repository, $host] = [$this->runSnapshot, $this->runWarnings, $this->runNotified, $this->runRepository, $this->runHost];
        $this->endedSnapshot = $snapshot;
        $this->runSnapshot = null;
        $this->runWarnings = [];
        $this->runNotified = false;
        $this->runRepository = $this->runHost = null;
        if ($job === null || $exit === ExitCode::USAGE) {
            return;
        }
        $invocation = strtolower($this->terminal->environment()['INVOCATION_ID'] ?? '');
        try {
            $this->laravel->make(RunJournal::class)->record(
                new RunRecord(
                    $job,
                    $started,
                    $this->clock->now(),
                    $exit,
                    $snapshot,
                    $warnings,
                    $failure?->getMessage(),
                    preg_match('/^[0-9a-f]{32}$/', $invocation) === 1 ? $invocation : null,
                    $notified,
                    $repository === null ? null : RepositoryKey::of($repository),
                    $host,
                ),
                $this->log,
            );
        } catch (Throwable $e) {
            $this->log->warning("Could not record the outcome of this {$job}: {$e->getMessage()}");
        }
    }

    #[\Override]
    protected function outcomeData(): array
    {
        return ['snapshot' => $this->endedSnapshot];
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
            $this->runNotified = true;

            return true;
        } catch (ArkhiveException $e) {
            $this->notificationFailure = $e;
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
