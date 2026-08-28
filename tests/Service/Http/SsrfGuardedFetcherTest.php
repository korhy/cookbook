<?php

declare(strict_types=1);

namespace App\Tests\Service\Http;

use App\Exception\Mcp\UrlFetchRejectedException;
use App\Service\Http\SsrfGuardedFetcher;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every test here names an SSRF technique the fetcher has to refuse. Literal IPs are used wherever
 * possible so the assertions do not depend on DNS being available or stable in CI.
 */
final class SsrfGuardedFetcherTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    public function testFetchesAnAllowlistedTarget(): void
    {
        $fetcher = $this->fetcher(new MockHttpClient(new MockResponse('<html>ok</html>')));

        $this->assertSame('<html>ok</html>', $fetcher->fetch('https://'.self::PUBLIC_IP.'/recipe'));
    }

    /**
     * The rebinding defence: the socket must be pinned to the address that was actually checked,
     * not re-resolved when the connection opens.
     */
    public function testConnectionIsPinnedToTheVerifiedAddress(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['resolve'] ?? [];

            return new MockResponse('<html>ok</html>');
        });

        $this->fetcher($client)->fetch('https://'.self::PUBLIC_IP.'/recipe');

        $this->assertSame([self::PUBLIC_IP => self::PUBLIC_IP], $seen);
    }

    public function testDisabledWhenNoAllowlistIsConfigured(): void
    {
        $fetcher = new SsrfGuardedFetcher(new MockHttpClient(), new Logger('t', [new TestHandler()]), '');

        $this->assertFalse($fetcher->isEnabled());
        $this->expectException(UrlFetchRejectedException::class);
        $fetcher->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    public function testPlainHttpIsRefused(): void
    {
        $this->assertRefused('http://'.self::PUBLIC_IP.'/recipe', 'Only https');
    }

    public function testNonHttpSchemesAreRefused(): void
    {
        $this->assertRefused('file:///etc/passwd', 'Only https');
        $this->assertRefused('gopher://'.self::PUBLIC_IP.'/', 'Only https');
    }

    public function testHostOutsideTheAllowlistIsRefused(): void
    {
        $this->assertRefused('https://evil.example.com/recipe', 'not on the import allowlist');
    }

    #[DataProvider('blockedAddresses')]
    public function testAddressesInBlockedRangesAreRefused(string $address): void
    {
        $fetcher = $this->fetcher(new MockHttpClient(new MockResponse('nope')), [$address]);

        // IPv6 literals must be bracketed in a URL, as any real caller would send them.
        $inUrl = str_contains($address, ':') ? '['.$address.']' : $address;

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('cannot be reached');
        $fetcher->fetch('https://'.$inUrl.'/');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedAddresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback elsewhere in 127/8' => ['127.1.2.3'];
        yield 'cloud metadata service' => ['169.254.169.254'];
        yield 'link-local' => ['169.254.1.1'];
        yield 'private 10/8' => ['10.0.0.1'];
        yield 'private 172.16/12' => ['172.16.5.4'];
        yield 'private 192.168/16' => ['192.168.1.1'];
        yield 'CGNAT 100.64/10' => ['100.64.0.1'];
        yield 'this network 0/8' => ['0.0.0.0'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 unique local' => ['fc00::1'];
        yield 'IPv6 link-local' => ['fe80::1'];
        yield 'IPv4-mapped IPv6 loopback' => ['::ffff:127.0.0.1'];
    }

    public function testRedirectToANonAllowlistedHostIsRefused(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://evil.example.com/x']]),
        ]);

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('not on the import allowlist');
        $this->fetcher($client)->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    public function testRedirectIntoABlockedRangeIsRefused(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://169.254.169.254/latest/meta-data/']]),
        ]);

        $fetcher = $this->fetcher($client, [self::PUBLIC_IP, '169.254.169.254']);

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('cannot be reached');
        $fetcher->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    public function testTooManyRedirectsIsRefused(): void
    {
        $responses = [];
        for ($i = 0; $i < 6; ++$i) {
            $responses[] = new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'https://'.self::PUBLIC_IP.'/hop'.$i],
            ]);
        }

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('redirects too many times');
        $this->fetcher(new MockHttpClient($responses))->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    public function testARedirectLoopIsRefused(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://'.self::PUBLIC_IP.'/recipe']]),
        ]);

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('redirects in a loop');
        $this->fetcher($client)->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    /**
     * A chunked response declares no Content-Length, so only the streaming cap can stop it. This
     * is the case that matters: a hostile endpoint simply omits the header.
     */
    public function testAnOversizedChunkedBodyIsStoppedWhileStreaming(): void
    {
        $chunks = static function (): \Generator {
            for ($i = 0; $i < 40; ++$i) {
                yield str_repeat('x', 100 * 1024);
            }
        };

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('too large');
        $this->fetcher(new MockHttpClient([new MockResponse($chunks())]))->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    public function testAnOversizedContentLengthIsRefusedBeforeReading(): void
    {
        $client = new MockHttpClient([new MockResponse(str_repeat('x', 3 * 1024 * 1024))]);

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('too large');
        $this->fetcher($client)->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    public function testANonOkStatusIsReported(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 404])]);

        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('HTTP 404');
        $this->fetcher($client)->fetch('https://'.self::PUBLIC_IP.'/recipe');
    }

    /**
     * The refusal must not report which address a host resolved to, or the tool becomes a way to
     * map the internal network from outside.
     */
    public function testTheRefusalDoesNotDiscloseTheResolvedAddress(): void
    {
        $fetcher = $this->fetcher(new MockHttpClient(), ['10.1.2.3']);

        try {
            $fetcher->fetch('https://10.1.2.3/');
            $this->fail('Expected a refusal.');
        } catch (UrlFetchRejectedException $e) {
            $this->assertStringNotContainsString('10.1.2.3', $e->getMessage());
        }
    }

    private function assertRefused(string $url, string $expectedMessage): void
    {
        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->fetcher(new MockHttpClient())->fetch($url);
    }

    /**
     * @param string[]|null $allowedHosts
     */
    private function fetcher(MockHttpClient $client, ?array $allowedHosts = null): SsrfGuardedFetcher
    {
        return new SsrfGuardedFetcher(
            $client,
            new Logger('test', [new TestHandler()]),
            implode(',', $allowedHosts ?? [self::PUBLIC_IP]),
        );
    }
}
