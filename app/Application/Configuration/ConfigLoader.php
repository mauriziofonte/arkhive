<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Configuration;

use Mfonte\Arkhive\Domain\Configuration\Config;

/**
 * Locates, parses and validates the configuration.
 */
final class ConfigLoader
{
    public function __construct(private readonly ConfigLocator $locator, private readonly string $defaultHost)
    {
    }

    public function load(?string $explicitPath = null, bool $checkSecrets = true): Config
    {
        $path = $this->locator->locate($explicitPath);

        return ConfigFactory::create(ConfigParser::parseFile($path), $path, $this->defaultHost, $checkSecrets);
    }

    public function locator(): ConfigLocator
    {
        return $this->locator;
    }
}
