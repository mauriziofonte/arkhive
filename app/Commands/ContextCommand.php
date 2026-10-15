<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\ExitCode;

/**
 * Internal diagnostic: prints the execution context ArkHive detected (the same line doctor reports).
 */
final class ContextCommand extends ArkhiveCommand
{
    protected $signature = 'internal:context {--sample-log : also emit one event of each level}';
    protected $description = 'Print the detected execution context';
    protected $hidden = true;

    protected function perform(): int
    {
        if ((bool) $this->option('sample-log')) {
            $this->log->info('sample info');
            $this->log->success('sample success');
            $this->log->notice('sample notice');
            $this->log->warning('sample warning');
            $this->log->error('sample error');
        }

        $this->result(json_encode([
            'interactive' => $this->context->interactive,
            'systemd' => $this->context->systemd,
            'colour' => $this->context->colour,
            'log_format' => $this->context->logFormat->value,
            'reason' => $this->context->reason,
            'log_target' => $this->context->logTarget->value,
            'log_target_reason' => $this->context->logTargetReason,
        ], JSON_THROW_ON_ERROR));

        return ExitCode::SUCCESS;
    }
}
