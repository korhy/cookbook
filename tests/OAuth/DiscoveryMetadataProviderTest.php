<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\OAuth\DiscoveryMetadataProvider;
use App\OAuth\McpResourceUri;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the provider derives on top of the resource URI: the metadata URL a `WWW-Authenticate`
 * challenge points at, and which well-known paths this server will answer a document for.
 *
 * The URI's own invariants are {@see McpResourceUriTest}'s business, and the documents as served
 * are `App\Tests\Controller\OAuthMetadataTest`'s. What is left here is the derivation, which is
 * why the URL generator is a stub rather than the real router.
 */
final class DiscoveryMetadataProviderTest extends TestCase
{
    public function testTheIssuerAndTheResourceUriComeFromTheValueObject(): void
    {
        $provider = $this->provider();

        self::assertSame('https://api.example.test', $provider->issuer());
        self::assertSame('https://api.example.test/api/v1/mcp', $provider->resourceUri());
    }

    public function testTheMetadataUrlCarriesTheResourcePath(): void
    {
        self::assertSame(
            'https://api.example.test/.well-known/oauth-protected-resource/api/v1/mcp',
            $this->provider()->protectedResourceMetadataUrl(),
        );
    }

    public function testTheCanonicalAndBarePathsBothDescribeTheResource(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->describesResourcePath('/api/v1/mcp'));
        self::assertTrue($provider->describesResourcePath(''));
    }

    public function testAnotherResourcePathIsNotDescribed(): void
    {
        $provider = $this->provider();

        self::assertFalse($provider->describesResourcePath('/api/v1/recipes'));
        // A prefix is not a match either: the comparison is equality, like everything else that
        // touches this value.
        self::assertFalse($provider->describesResourcePath('/api/v1'));
        self::assertFalse($provider->describesResourcePath('/api/v1/mcp/'));
    }

    private function provider(): DiscoveryMetadataProvider
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

        return new DiscoveryMetadataProvider(
            $urlGenerator,
            new McpResourceUri('https://api.example.test/api/v1/mcp'),
            ['authorization_code', 'refresh_token'],
            ['mcp:write'],
        );
    }
}
