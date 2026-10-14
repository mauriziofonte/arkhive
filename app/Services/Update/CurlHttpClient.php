<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Update;

use Mfonte\Arkhive\Exceptions\DataException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\RefusedException;
use Mfonte\Arkhive\Exceptions\SoftwareException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * HttpClient on the curl extension. libcurl reads HTTPS_PROXY / https_proxy itself; the proxy is
 * also set explicitly so that both spellings behave the same.
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * @param array<string, string> $environment
     */
    public function __construct(private readonly array $environment, private readonly int $timeout = 60)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $handle = $this->handle($url, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw new UnavailableException("Cannot fetch {$url}: " . curl_error($handle), hint: self::hint());
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
    }

    public function download(string $url, string $path, int $maxBytes, array $headers = []): int
    {
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new RefusedException("Cannot create {$path}");
        }
        $written = 0;
        $tooLarge = false;
        $failedWrite = false;
        $handle = $this->handle($url, $headers);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use ($file, $maxBytes, &$written, &$tooLarge, &$failedWrite): int {
            $written += strlen($data);
            if ($written > $maxBytes) {
                $tooLarge = true;

                return 0;
            }
            if (fwrite($file, $data) !== strlen($data)) {
                $failedWrite = true;

                return 0;
            }

            return strlen($data);
        });
        $ok = curl_exec($handle);
        fclose($file);
        if ($tooLarge) {
            throw new DataException("The download of {$url} is larger than the expected " . $maxBytes . ' bytes: stopped');
        }
        if ($failedWrite) {
            throw new IoException("Cannot write {$path}: the filesystem is full or not writable");
        }
        if ($ok === false) {
            throw new UnavailableException("Cannot download {$url}: " . curl_error($handle), hint: self::hint());
        }

        return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    }

    /**
     * @param array<string, string> $headers
     */
    private function handle(string $url, array $headers): \CurlHandle
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SoftwareException("Cannot fetch {$url}: curl_init failed");
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FAILONERROR => false,
        ]);
        $proxy = Proxy::fromEnvironment($this->environment, $url);
        if ($proxy !== null) {
            curl_setopt($handle, CURLOPT_PROXY, $proxy);
        }

        return $handle;
    }

    private static function hint(): string
    {
        return 'Check the network connection, DNS and HTTPS_PROXY; GitHub must be reachable from this host';
    }
}
