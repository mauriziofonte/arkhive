<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Services\Output\UsageErrors;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand as BaseSummaryCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The command list, shown by `arkhive` alone. Laravel Zero also hands it every unknown command:
 * that is a usage error (exit 2) naming the closest commands, not the list with exit 0.
 */
final class SummaryCommand extends BaseSummaryCommand
{
    /** the parent's execute() reads static::FORMAT, and its own constant is private */
    protected const FORMAT = 'txt';

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $first = $input->getFirstArgument();
        $application = $this->getApplication();
        if ($first !== null && $first !== 'list' && $application !== null && !$application->has($first)) {
            try {
                $application->find($first);
            } catch (CommandNotFoundException $e) {
                $alternatives = $e->getAlternatives();

                return UsageErrors::report(
                    "Unknown command \"{$first}\"",
                    ($alternatives === [] ? '' : 'Did you mean: ' . implode(', ', $alternatives) . '? ') . 'List the commands with: arkhive list',
                );
            }
        }

        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $e) {
            return UsageErrors::report($e->getMessage(), 'List the commands with: arkhive list');
        }
    }
}
