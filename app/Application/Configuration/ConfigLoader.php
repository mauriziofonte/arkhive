<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Configuration;

use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Configuration\ConfigFileAccess;
use Mfonte\Arkhive\Domain\Configuration\SecretKeys;
use Mfonte\Arkhive\Exceptions\PermissionException;

/**
 * Locates, parses and validates the configuration, and checks who else can read or change it.
 */
final class ConfigLoader
{
    public function __construct(private readonly ConfigLocator $locator, private readonly string $defaultHost)
    {
    }

    public function load(?string $explicitPath = null, bool $checkSecrets = true): Config
    {
        $path = $this->locator->locate($explicitPath);
        $values = ConfigParser::parseFile($path);
        $warnings = [];
        $access = self::access($path, $values, posix_geteuid());
        $problem = $access?->problem();
        if ($access !== null && $problem !== null) {
            if ($access->refused()) {
                throw new PermissionException($problem, $access->hint());
            }
            $warnings[] = "{$problem}: {$access->hint()} (a later ArkHive version will refuse it)";
        }

        return ConfigFactory::create($values, $path, $this->defaultHost, $checkSecrets, $warnings);
    }

    /**
     * The file's mode and owner against the user ArkHive runs as; null when stat fails (the file
     * was read a moment before, so only a race gets there).
     *
     * @param array<string, string> $values
     */
    public static function access(string $path, array $values, int $user): ?ConfigFileAccess
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        return new ConfigFileAccess($path, $stat['mode'] & 07777, $stat['uid'], $user, SecretKeys::found($values));
    }

    public function locator(): ConfigLocator
    {
        return $this->locator;
    }
}
