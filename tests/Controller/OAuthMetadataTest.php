<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two OAuth discovery documents, asserted as whole JSON bodies.
 *
 * These are a **contract parsed by software we do not control**: an MCP client reads them to find
 * the authorization server, and a renamed member or a dropped one fails the flow with nothing in
 * our logs to show for it. So every test here compares the complete decoded body with `assertSame`
 * — key order included — rather than checking a status code and spot-checking a field. A member
 * that changes shape has to be changed here too, deliberately, which is the point.
 *
 * The expected host is `http://localhost`, which is what `MCP_RESOURCE_URI` is set to in
 * `.env.test`; `KernelBrowser` requests come from that host with no port.
 */
final class OAuthMetadataTest extends WebTestCase
{
    private const RESOURCE_URI = 'http://localhost/api/v1/mcp-authoring';

    private const ISSUER = 'http://localhost';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAuthorizationServerMetadataDocument(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/oauth2/authorize',
            'token_endpoint' => self::ISSUER.'/oauth2/token',
            // Derived from the enabled grants, not stated: `code` is the authorization code
            // grant's response type, and the implicit grant that would add `token` is off.
            'response_types_supported' => ['code'],
            // Read back out of the AuthorizationServer definition by OAuthDiscoveryPass, so
            // turning a grant off in league_oauth2_server.yaml fails this assertion instead of
            // publishing a flow the token endpoint refuses.
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // The single client is public and holds no secret.
            'token_endpoint_auth_methods_supported' => ['none'],
            // Mandatory for an MCP authorization server, and never `plain`.
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['mcp:write'],
        ], $this->payload());
    }

    public function testProtectedResourceMetadataDocument(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource/api/v1/mcp-authoring');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            // The canonical resource URI, which must be byte-identical to the `resource`
            // parameter a client sends and to the `aud` claim on every issued token.
            'resource' => self::RESOURCE_URI,
            'authorization_servers' => [self::ISSUER],
            'scopes_supported' => ['mcp:write'],
            'bearer_methods_supported' => ['header'],
        ], $this->payload());
    }

    /**
     * RFC 9728 §3.1 puts the resource's path after the well-known segment, which is the URL the
     * `WWW-Authenticate` challenge points at. The bare path is served as well, on purpose: it is
     * what a client treating the resource identifier as an origin will try, and this application
     * hosts exactly one protected resource, so there is no other document it could mean.
     */
    public function testProtectedResourceMetadataIsAlsoServedAtTheBarePath(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource');

        self::assertResponseIsSuccessful();
        self::assertSame(self::RESOURCE_URI, $this->payload()['resource'] ?? null);
    }

    public function testNoProtectedResourceMetadataIsServedForAnotherResourcePath(): void
    {
        // /api/v1/recipes is a real resource of this application, but it is not an OAuth
        // protected resource. Answering here would tell a client the MCP document describes it.
        $this->client->request('GET', '/.well-known/oauth-protected-resource/api/v1/recipes');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Both documents are read by a client that holds no credential yet, so they must answer
     * anonymously. `security.yaml`'s `oauth2_public` firewall is what makes that true; this is the
     * assertion that notices if the pattern stops covering them.
     */
    public function testBothDocumentsAreReachableWithoutAuthentication(): void
    {
        foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource/api/v1/mcp-authoring'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful(\sprintf('%s must answer anonymously', $path));
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payload(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($payload);

        return $payload;
    }
}
