<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Services\Update\HttpClient;
use Mfonte\Arkhive\Services\Update\HttpResponse;

/**
 * Canned answers by URL; records every request with its headers. An unknown URL is a 404.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, HttpResponse|ArkhiveException> */
    public array $responses = [];

    /** @var list<array{string, array<string, string>}> */
    public array $requests = [];

    public function on(string $url, int $status, string $body): self
    {
        $this->responses[$url] = new HttpResponse($status, $body);

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function json(string $url, array|string $data, int $status = 200): self
    {
        return $this->on($url, $status, is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = [$url, $headers];
        $response = $this->responses[$url] ?? new HttpResponse(404, '{"message":"Not Found"}');
        if ($response instanceof ArkhiveException) {
            throw $response;
        }

        return $response;
    }

    public function download(string $url, string $path, int $maxBytes, array $headers = []): int
    {
        $response = $this->get($url, $headers);
        if (file_exists($path)) {
            throw new ArkhiveException("Cannot create {$path}");
        }
        if (strlen($response->body) > $maxBytes) {
            throw new ArkhiveException("The download of {$url} is larger than the expected {$maxBytes} bytes: stopped");
        }
        file_put_contents($path, $response->body);

        return $response->status;
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(static fn (array $request): string => $request[0], $this->requests);
    }
}
