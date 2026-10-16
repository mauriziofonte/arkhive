<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Infrastructure\Http\Proxy;
use Mfonte\Arkhive\Infrastructure\Http\StreamHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * spec §9 "honours HTTPS_PROXY".
 */
final class ProxyTest extends TestCase
{
    public function testHttpsUsesHttpsProxyInEitherCase(): void
    {
        self::assertSame('http://proxy.example.com:3128', Proxy::fromEnvironment(['HTTPS_PROXY' => 'http://proxy.example.com:3128'], 'https://api.github.com/x'));
        self::assertSame('http://lower.example.com:8080', Proxy::fromEnvironment(['https_proxy' => 'http://lower.example.com:8080'], 'https://api.github.com/x'));
        self::assertNull(Proxy::fromEnvironment(['http_proxy' => 'http://proxy.example.com:3128'], 'https://api.github.com/x'), 'http_proxy is for http only');
        self::assertSame('http://proxy.example.com:3128', Proxy::fromEnvironment(['HTTP_PROXY' => 'http://proxy.example.com:3128'], 'http://releases.example.com/x'));
        self::assertNull(Proxy::fromEnvironment(['HTTPS_PROXY' => ''], 'https://api.github.com/x'));
    }

    public function testNoProxy(): void
    {
        $env = ['HTTPS_PROXY' => 'http://proxy.example.com:3128'];
        self::assertNull(Proxy::fromEnvironment([...$env, 'NO_PROXY' => 'localhost, .github.com'], 'https://api.github.com/x'));
        self::assertNull(Proxy::fromEnvironment([...$env, 'no_proxy' => 'api.github.com'], 'https://API.github.com/x'));
        self::assertNull(Proxy::fromEnvironment([...$env, 'NO_PROXY' => '*'], 'https://api.github.com/x'));
        self::assertSame('http://proxy.example.com:3128', Proxy::fromEnvironment([...$env, 'NO_PROXY' => 'hub.com,example.org'], 'https://api.github.com/x'), 'only whole labels match');
    }

    public function testStreamProxyAndStatus(): void
    {
        self::assertSame('tcp://proxy.example.com:3128', StreamHttpClient::streamProxy('http://proxy.example.com:3128'));
        self::assertSame('tcp://proxy.example.com:80', StreamHttpClient::streamProxy('proxy.example.com'));
        self::assertSame(200, StreamHttpClient::status(['HTTP/1.1 302 Found', 'Location: /x', 'HTTP/1.1 200 OK', 'Content-Type: text/plain']));
        self::assertSame(0, StreamHttpClient::status('nothing'));

        $this->expectException(ArkhiveException::class);
        StreamHttpClient::streamProxy('http://');
    }
}
