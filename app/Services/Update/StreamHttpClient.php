<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Update;

use Mfonte\Arkhive\Exceptions\ConfigException;
use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * HttpClient on PHP's http:// and https:// stream wrappers, for hosts without the curl extension
 * (https needs the openssl extension). The proxy is given to the wrapper as tcp://host:port.
 */
final class StreamHttpClient implements HttpClient
{
    private const CHUNK = 1048576;

    /**
     * @param array<string, string> $environment
     */
    public function __construct(private readonly array $environment, private readonly int $timeout = 60)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        [$stream, $status] = $this->open($url, $headers);
        $body = stream_get_contents($stream);
        fclose($stream);
        if ($body === false) {
            throw new UnavailableException("Cannot read the answer of {$url}");
        }

        return new HttpResponse($status, $body);
    }

    public function download(string $url, string $path, int $maxBytes, array $headers = []): int
    {
        [$stream, $status] = $this->open($url, $headers);
        $file = @fopen($path, 'xb');
        if ($file === false) {
            fclose($stream);

            throw new RefusedException("Cannot create {$path}");
        }
        $written = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if ($chunk === false) {
                    throw new TemporaryException("The download of {$url} was interrupted");
                }
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new DataException("The download of {$url} is larger than the expected {$maxBytes} bytes: stopped");
                }
                if ($chunk !== '' && fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new IoException("Cannot write {$path}: the filesystem is full or not writable");
                }
            }
            if (stream_get_meta_data($stream)['timed_out']) {
                throw new TemporaryException("The download of {$url} timed out");
            }
        } finally {
            fclose($stream);
            fclose($file);
        }

        return $status;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{resource, int}
     */
    private function open(string $url, array $headers): array
    {
        if (str_starts_with($url, 'https://') && !extension_loaded('openssl')) {
            throw new UnavailableException('self-update needs the PHP extension curl or openssl to reach ' . $url);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $http = [
            'method' => 'GET',
            'header' => $lines,
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ];
        $proxy = Proxy::fromEnvironment($this->environment, $url);
        if ($proxy !== null) {
            $http['proxy'] = self::streamProxy($proxy);
            $http['request_fulluri'] = true;
        }
        $http['header'][] = 'Connection: close';
        $stream = @fopen($url, 'rb', false, stream_context_create(['http' => $http]));
        if ($stream === false) {
            $error = error_get_last();

            throw new UnavailableException("Cannot fetch {$url}" . ($error === null ? '' : ': ' . $error['message']), hint: 'Check the network connection, DNS and HTTPS_PROXY; GitHub must be reachable from this host');
        }

        return [$stream, self::status(stream_get_meta_data($stream)['wrapper_data'] ?? [])];
    }

    /**
     * http://proxy.example.com:3128 → tcp://proxy.example.com:3128 (what the stream wrapper wants).
     */
    public static function streamProxy(string $proxy): string
    {
        $parts = parse_url(str_contains($proxy, '://') ? $proxy : 'http://' . $proxy);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new ConfigException("The proxy {$proxy} is not a valid URL");
        }

        return 'tcp://' . $parts['host'] . ':' . ($parts['port'] ?? 80);
    }

    /**
     * The status of the last response (after redirects) from the wrapper's header lines.
     */
    public static function status(mixed $headers): int
    {
        $status = 0;
        if (!is_array($headers)) {
            return $status;
        }
        foreach ($headers as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }
}
