<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

use Mfonte\Arkhive\Exceptions\ArkhiveException;

/**
 * Plain HTTPS GET, in PHP (curl extension or streams): never a curl or wget binary (spec §9).
 * Redirects are followed; HTTPS_PROXY is honoured.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ArkhiveException the request could not be made (DNS, TLS, timeout): not an HTTP status
     */
    public function get(string $url, array $headers = []): HttpResponse;

    /**
     * Writes the body to $path (created, must not exist); stops with an error past $maxBytes.
     *
     * @param array<string, string> $headers
     *
     * @return int the HTTP status; on a non-2xx status $path holds the error body
     *
     * @throws ArkhiveException
     */
    public function download(string $url, string $path, int $maxBytes, array $headers = []): int;
}
