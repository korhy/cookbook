<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\OAuth\DiscoveryMetadataProvider;
use App\Security\McpBearerChallenge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The challenge is tested in isolation because it is **not wired yet** — phase 5 attaches it to
 * the `mcp_authoring` firewall. Until then this is the only thing that says whether it answers
 * correctly, and the `resource_metadata` pointer is the part that matters: it is how a client gets
 * from a bare 401 to the authorization server without a single URL configured by hand.
 */
final class McpBearerChallengeTest extends TestCase
{
    private const METADATA_URL = 'https://api.example.test/.well-known/oauth-protected-resource/api/v1/mcp';

    /**
     * RFC 6750 §3.1: a request that carried no credentials has not failed authentication, so the
     * challenge names no error code. It still has to carry the pointer — that caller is precisely
     * the one who needs it.
     */
    public function testAnUnauthenticatedRequestGetsAPointerAndNoErrorCode(): void
    {
        $response = $this->challenge()->start(new Request());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(
            \sprintf('Bearer resource_metadata="%s"', self::METADATA_URL),
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function testARequestCarryingABadTokenIsToldTheTokenIsInvalid(): void
    {
        $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-real-token']);

        $response = $this->challenge()->start($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $header = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringStartsWith('Bearer error="invalid_token", error_description="', $header);
        self::assertStringContainsString(\sprintf('resource_metadata="%s"', self::METADATA_URL), $header);
    }

    /**
     * The spec's other half: a token that authenticates but does not carry the scope. A 401 here
     * would send a client back through a flow it has already completed, so it has to be a 403.
     */
    public function testAMissingScopeIsA403NamingTheScopeAndTheError(): void
    {
        $response = $this->challenge()->handle(new Request(), new AccessDeniedException());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $header = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringStartsWith('Bearer error="insufficient_scope", error_description="', $header);
        self::assertStringContainsString('scope="mcp:write"', $header);
        self::assertStringContainsString(\sprintf('resource_metadata="%s"', self::METADATA_URL), $header);
    }

    /**
     * The header is for the client; the body is for whoever is holding a terminal. Both say the
     * same thing, so a `curl` session and a parsed challenge cannot disagree.
     */
    public function testTheBodyRepeatsTheChallengeParameters(): void
    {
        $response = $this->challenge()->handle(new Request(), new AccessDeniedException());

        self::assertSame([
            'error' => 'insufficient_scope',
            'error_description' => 'The access token does not carry the scope this operation requires.',
            'scope' => 'mcp:write',
            'resource_metadata' => self::METADATA_URL,
        ], json_decode((string) $response->getContent(), true));
    }

    private function challenge(): McpBearerChallenge
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $parameters = []): string => match ($route) {
                'oauth2_metadata_protected_resource' => '/.well-known/oauth-protected-resource'.($parameters['resourcePath'] ?? ''),
                default => throw new \LogicException(\sprintf('Unexpected route "%s".', $route)),
            });

        return new McpBearerChallenge(new DiscoveryMetadataProvider(
            $urlGenerator,
            'https://api.example.test/api/v1/mcp',
            ['authorization_code', 'refresh_token'],
            ['mcp:write'],
        ));
    }
}
