<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Services\Config\Config;
use Mfonte\Arkhive\Services\Config\ConfigLoader;
use Mfonte\Arkhive\Services\Config\ConfigLocator;
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
}
