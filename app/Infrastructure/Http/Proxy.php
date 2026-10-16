<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Http;

/**
 * The proxy for a URL from the environment: HTTPS_PROXY / https_proxy for https, http_proxy /
 * HTTP_PROXY for http, NO_PROXY honoured for exact hosts and domain suffixes.
 */
final class Proxy
{
    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment, string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $names = $scheme === 'https' ? ['HTTPS_PROXY', 'https_proxy'] : ['http_proxy', 'HTTP_PROXY'];
        $proxy = null;
        foreach ($names as $name) {
            if (($environment[$name] ?? '') !== '') {
                $proxy = $environment[$name];
                break;
            }
        }
        if ($proxy === null || self::bypassed($host, $environment['NO_PROXY'] ?? $environment['no_proxy'] ?? '')) {
            return null;
        }

        return $proxy;
    }

    private static function bypassed(string $host, string $noProxy): bool
    {
        foreach (explode(',', $noProxy) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '*') {
                return true;
            }
            $entry = ltrim($entry, '.');
            if ($entry !== '' && ($host === $entry || str_ends_with($host, '.' . $entry))) {
                return true;
            }
        }

        return false;
    }
}
