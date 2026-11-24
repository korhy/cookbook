<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\OAuth\DiscoveryMetadataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The rules around the canonical resource URI — the one value three separate mechanisms compare by
 * exact string equality (the RFC 9728 document, the `aud` claim, and phase 5's audience
 * validation). A mistake in it does not fail loudly on its own: it rejects every token, with
 * nothing in the logs that says why. These tests are the loud failure.
 *
 * `App\Tests\Controller\OAuthMetadataTest` covers the documents as served; this covers the
 * derivation rules, which is why the URL generator is a stub rather than the real router.
 */
final class DiscoveryMetadataProviderTest extends TestCase
{
    public function testTheIssuerIsTheOriginOfTheResourceUri(): void
    {
        $provider = $this->provider('https://api.example.test/api/v1/mcp');

        self::assertSame('https://api.example.test', $provider->issuer());
        self::assertSame('https://api.example.test/api/v1/mcp', $provider->resourceUri());
    }

    public function testTheIssuerKeepsANonDefaultPort(): void
    {
        // The dev stack publishes the app on :8001, so dropping the port here would publish a
        // document pointing at a server that is not listening.
        self::assertSame('http://localhost:8001', $this->provider('http://localhost:8001/api/v1/mcp')->issuer());
    }

    public function testTheMetadataUrlCarriesTheResourcePath(): void
    {
        self::assertSame(
            'https://api.example.test/.well-known/oauth-protected-resource/api/v1/mcp',
            $this->provider('https://api.example.test/api/v1/mcp')->protectedResourceMetadataUrl(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidResourceUriProvider(): iterable
    {
        yield 'a trailing slash — the exact-match comparison would reject every token' => ['https://api.example.test/api/v1/mcp/'];
        yield 'no scheme' => ['api.example.test/api/v1/mcp'];
        yield 'a scheme that is not http(s)' => ['ftp://api.example.test/api/v1/mcp'];
        yield 'a query string' => ['https://api.example.test/api/v1/mcp?debug=1'];
        yield 'a fragment' => ['https://api.example.test/api/v1/mcp#mcp'];
        yield 'embedded credentials' => ['https://user:pass@api.example.test/api/v1/mcp'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidResourceUriProvider')]
    public function testAnUnusableResourceUriIsRefusedOnConstruction(string $resourceUri): void
    {
        $this->expectException(\LogicException::class);

        $this->provider($resourceUri);
    }

    public function testTheCanonicalAndBarePathsBothDescribeTheResource(): void
    {
        $provider = $this->provider('https://api.example.test/api/v1/mcp');

        self::assertTrue($provider->describesResourcePath('/api/v1/mcp'));
        self::assertTrue($provider->describesResourcePath(''));
    }

    public function testAnotherResourcePathIsNotDescribed(): void
    {
        $provider = $this->provider('https://api.example.test/api/v1/mcp');

        self::assertFalse($provider->describesResourcePath('/api/v1/recipes'));
        // A prefix is not a match either: the comparison is equality, like everything else that
        // touches this value.
        self::assertFalse($provider->describesResourcePath('/api/v1'));
        self::assertFalse($provider->describesResourcePath('/api/v1/mcp/'));
    }

    private function provider(string $resourceUri): DiscoveryMetadataProvider
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $parameters = []): string => match ($route) {
                'oauth2_authorize' => '/oauth2/authorize',
                'oauth2_token' => '/oauth2/token',
                'oauth2_metadata_protected_resource' => '/.well-known/oauth-protected-resource'.($parameters['resourcePath'] ?? ''),
                default => throw new \LogicException(\sprintf('Unexpected route "%s".', $route)),
            });

        return new DiscoveryMetadataProvider($urlGenerator, $resourceUri, ['authorization_code', 'refresh_token'], ['mcp:write']);
    }
}
