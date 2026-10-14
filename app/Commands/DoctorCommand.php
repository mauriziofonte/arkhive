<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Application\Configuration\ConfigLocator;
use Mfonte\Arkhive\Application\Diagnostics\Doctor;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\Port\SnapshotStoreFactory;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Diagnostics\Check;
use Mfonte\Arkhive\Domain\Diagnostics\CheckStatus;
use Mfonte\Arkhive\ExitCode;

/**
 * spec §10.6: diagnosis of the environment and the repository. Exit 0 when everything is fine,
 * 3 with warnings only, 1 when a check failed.
 */
final class DoctorCommand extends RepositoryCommand
{
    private const NAME_WIDTH = 19;

    protected $signature = 'doctor
        {--json : Print the checks as JSON on stdout}';

    protected $description = 'Check PHP, restic, the configuration, the repository, the latest snapshot and the clock, and say how to fix problems';

    protected function perform(): int
    {
        $doctor = new Doctor(
            $this->laravel->make(Dependencies::class),
            $this->laravel->make(SnapshotStoreFactory::class),
            $this->laravel->make(ProcessRunner::class),
            $this->cleanup,
            $this->clock,
            $this->context,
            $this->terminal->environment(),
            posix_geteuid(),
            self::hostname(),
        );
        $checks = $doctor->run(ConfigLocator::fromEnvironment($this->terminal->environment()), self::stringOption($this->option('config')), $this->bareMetal());
        $overall = Doctor::overall($checks);

        if ((bool) $this->option('json')) {
            $this->result(json_encode(['status' => $overall->value, 'checks' => array_map(static fn (Check $check): array => $check->toArray(), $checks)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->result($this->render($checks));
        }

        $failed = count(array_filter($checks, static fn (Check $c): bool => $c->status === CheckStatus::Failed));
        $warnings = count(array_filter($checks, static fn (Check $c): bool => $c->status === CheckStatus::Warning));
        $summary = sprintf('%d failed, %d warning(s), %d checks', $failed, $warnings, count($checks));

        return match ($overall) {
            CheckStatus::Failed => $this->finish('error', "Doctor: {$summary}", ExitCode::FAILURE),
            CheckStatus::Warning => $this->finish('warning', "Doctor: {$summary}", ExitCode::WARNING),
            default => $this->finish('success', 'Doctor: everything looks fine', ExitCode::SUCCESS),
        };
    }

    private function finish(string $level, string $message, int $exit): int
    {
        match ($level) {
            'error' => $this->log->error($message),
            'warning' => $this->log->warning($message),
            default => $this->log->success($message),
        };

        return $exit;
    }

    /**
     * --repo and friends as given, unchecked: the doctor reports what is wrong with them.
     */
    private function bareMetal(): ?ResticSettings
    {
        $repository = self::stringOption($this->option('repo'));
        if ($repository === null) {
            return null;
        }
        $passwordFile = self::stringOption($this->option('password-file')) ?? '';
        if ($passwordFile !== '' && !str_starts_with($passwordFile, '/')) {
            $passwordFile = (getcwd() ?: '.') . '/' . $passwordFile;
        }

        return new ResticSettings($repository, $passwordFile, self::stringOption($this->option('sftp-command')), host: self::hostname());
    }

    /**
     * @param list<Check> $checks
     */
    private function render(array $checks): string
    {
        $lines = [];
        $indent = str_repeat(' ', 6 + self::NAME_WIDTH);
        foreach ($checks as $check) {
            $message = str_replace("\n", "\n" . $indent, $check->message);
            $lines[] = sprintf('%-4s  %s%s', $check->status->label(), str_pad($check->name, self::NAME_WIDTH), $message);
            if ($check->hint !== null && in_array($check->status, [CheckStatus::Failed, CheckStatus::Warning, CheckStatus::Info], true)) {
                $lines[] = $indent . 'hint: ' . str_replace("\n", "\n{$indent}      ", $check->hint);
            }
        }

        return implode("\n", $lines);
    }
}
