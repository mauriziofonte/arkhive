<?php

declare(strict_types=1);

namespace Tests\Integration;

use Mfonte\Arkhive\Application\Port\HttpClient;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Infrastructure\Http\CurlHttpClient;
use Mfonte\Arkhive\Infrastructure\Http\StreamHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * Both HTTP clients of self-update against a local `php -S` server: statuses, redirects, headers,
 * size limits and the proxy. Never a real host.
 */
final class HttpClientTest extends TestCase
{
    use TempDir;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/Fixtures/http/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);
        self::$server = $server;
        self::$base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', $port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50000);
        }
        self::fail('the php -S server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clients(): iterable
    {
        yield 'curl' => ['curl'];
        yield 'streams' => ['streams'];
    }

    /**
     * @param array<string, string> $environment
     */
    private static function client(string $kind, array $environment = []): HttpClient
    {
        if ($kind === 'curl' && !extension_loaded('curl')) {
            self::markTestSkipped('the curl extension is not loaded');
        }

        return $kind === 'curl' ? new CurlHttpClient($environment, 10) : new StreamHttpClient($environment, 10);
    }

    #[DataProvider('clients')]
    public function testGetFollowsRedirectsAndKeepsTheStatus(string $kind): void
    {
        $client = self::client($kind);

        $ok = $client->get(self::$base . '/redirect');
        self::assertSame(200, $ok->status);
        self::assertTrue($ok->ok());
        self::assertSame('v2.0.1', json_decode($ok->body, true)['tag_name']);

        $query = $client->get(self::$base . '/json?per_page=100');
        self::assertSame('per_page=100', json_decode($query->body, true)['query']);

        $missing = $client->get(self::$base . '/nothing');
        self::assertSame(404, $missing->status);
        self::assertFalse($missing->ok());
        self::assertSame('{"message":"Not Found"}', $missing->body);
    }

    #[DataProvider('clients')]
    public function testHeadersAreSent(string $kind): void
    {
        $body = self::client($kind)->get(self::$base . '/headers', ['User-Agent' => 'ArkHive/2.0.0', 'Accept' => 'application/vnd.github+json'])->body;

        self::assertSame(['user_agent' => 'ArkHive/2.0.0', 'accept' => 'application/vnd.github+json'], json_decode($body, true));
    }

    #[DataProvider('clients')]
    public function testDownloadWritesTheFileAndEnforcesTheLimit(string $kind): void
    {
        $dir = $this->tempDir();
        $client = self::client($kind);

        self::assertSame(200, $client->download(self::$base . '/big', "{$dir}/big", 300000));
        self::assertSame(300000, filesize("{$dir}/big"));

        self::assertSame(404, $client->download(self::$base . '/nothing', "{$dir}/missing", 1000));

        try {
            $client->download(self::$base . '/big', "{$dir}/limited", 1000);
            self::fail('the size limit was ignored');
        } catch (ArkhiveException $e) {
            self::assertSame('The download of ' . self::$base . '/big is larger than the expected 1000 bytes: stopped', $e->getMessage());
        }

        $this->expectExceptionMessage("Cannot create {$dir}/big");
        $client->download(self::$base . '/big', "{$dir}/big", 300000);
    }

    #[DataProvider('clients')]
    public function testTheProxyFromTheEnvironmentIsUsed(string $kind): void
    {
        // the local server stands in for the proxy: it sees the absolute URL in the request line
        $client = self::client($kind, ['http_proxy' => self::$base]);

        $response = $client->get('http://releases.example.com/proxied?x=1');

        self::assertSame(200, $response->status);
        self::assertSame('http://releases.example.com/proxied?x=1', $response->body);
    }

    #[DataProvider('clients')]
    public function testAConnectionFailureIsAnError(string $kind): void
    {
        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessageMatches('#^Cannot fetch http://127\.0\.0\.1:1/json#');
        self::client($kind)->get('http://127.0.0.1:1/json');
    }
}
