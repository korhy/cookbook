<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\OAuth\McpResourceUri;
use App\Security\McpTokenAudienceVerifier;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use League\Bundle\OAuth2ServerBundle\Security\Exception\OAuth2AuthenticationFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * RFC 8707 audience validation — the obligation the MCP authorization specification puts on a
 * resource server, and the one `league/oauth2-server` does not discharge: its
 * `BearerTokenValidator` checks the signature, the validity window and revocation, and never looks
 * at `aud`.
 *
 * The signature is irrelevant to this class and the tokens below are signed with a throwaway HMAC
 * key on purpose: by the time `CheckPassportEvent` fires, the resource server has already verified
 * the real signature. What is under test is the comparison, and that it is an exact one — a
 * near-miss audience is indistinguishable from an attack, so it must be refused just as firmly.
 */
final class McpTokenAudienceVerifierTest extends TestCase
{
    private const RESOURCE_URI = 'https://api.example.test/api/v1/mcp-authoring';

    public function testATokenNamingTheResourceIsAccepted(): void
    {
        $this->verify($this->request([self::RESOURCE_URI]));

        $this->expectNotToPerformAssertions();
    }

    /**
     * The real shape of one of our tokens: `[<client id>, <resource uri>]`. Membership, not
     * position — the client entry sits first and must not get in the way.
     */
    public function testTheResourceIsFoundAlongsideTheClientIdentifier(): void
    {
        $this->verify($this->request(['cookbook-mcp-authoring', self::RESOURCE_URI]));

        $this->expectNotToPerformAssertions();
    }

    /**
     * The confused deputy this whole check exists to foreclose: a token this very server signed,
     * valid in every other respect, issued for a different protected resource.
     */
    public function testATokenForAnotherResourceIsRefused(): void
    {
        $this->expectException(OAuth2AuthenticationFailedException::class);
        $this->verify($this->request(['https://elsewhere.example.test/api/v1/mcp-authoring']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nearMissProvider(): iterable
    {
        yield 'trailing slash' => [self::RESOURCE_URI.'/'];
        yield 'http instead of https' => ['http://api.example.test/api/v1/mcp-authoring'];
        yield 'the public read endpoint' => ['https://api.example.test/api/v1/mcp'];
        yield 'a path below it' => [self::RESOURCE_URI.'/extra'];
        yield 'a lookalike host' => ['https://api.example.test.evil.test/api/v1/mcp-authoring'];
    }

    /**
     * Exact equality, stated case by case. Every one of these would pass a prefix match, a
     * `str_contains`, or a host comparison — which is why McpResourceUri exists as a validated
     * value object rather than a string somebody normalises differently in each consumer.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nearMissProvider')]
    public function testANearMissAudienceIsRefused(string $audience): void
    {
        $this->expectException(OAuth2AuthenticationFailedException::class);
        $this->verify($this->request([$audience]));
    }

    public function testATokenWithNoAudienceAtAllIsRefused(): void
    {
        $this->expectException(OAuth2AuthenticationFailedException::class);
        $this->verify($this->request([]));
    }

    /**
     * RFC 7519 §4.1.3 allows `aud` to be a single string rather than an array. Nothing this server
     * issues takes that form, but a token it did not issue is exactly what this class inspects.
     */
    public function testTheSingleStringAudienceFormIsUnderstood(): void
    {
        $jwt = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedBy('https://api.example.test')
            ->getToken(new Sha256(), InMemory::plainText(str_repeat('k', 32)))
            ->toString();

        // Rebuild the payload by hand: the builder always writes a list.
        $parts = explode('.', $jwt);
        $parts[1] = rtrim(strtr(base64_encode((string) json_encode(['aud' => self::RESOURCE_URI])), '+/', '-_'), '=');

        $this->verify($this->requestWithToken(implode('.', $parts)));

        $this->expectNotToPerformAssertions();
    }

    public function testAnUnparseableTokenIsRefused(): void
    {
        $this->expectException(OAuth2AuthenticationFailedException::class);
        $this->verify($this->requestWithToken('this.is.not-a-jwt'));
    }

    public function testAMissingAuthorizationHeaderIsRefused(): void
    {
        $this->expectException(OAuth2AuthenticationFailedException::class);
        $this->verify(Request::create('/api/v1/mcp-authoring', 'POST'));
    }

    /**
     * The refusal says nothing about what the token *did* name. The caller is unauthenticated at
     * this point, and the audiences this server issues for are not theirs to enumerate — the RFC
     * 9728 document the challenge points at is the legitimate way to find out.
     */
    public function testTheRefusalDoesNotDiscloseTheAudienceItFound(): void
    {
        try {
            $this->verify($this->request(['https://elsewhere.example.test/api/v1/mcp-authoring']));
            self::fail('Expected the verifier to refuse a token issued for another resource.');
        } catch (OAuth2AuthenticationFailedException $e) {
            self::assertStringNotContainsString('elsewhere.example.test', $e->getMessage());
        }
    }

    private function verify(Request $request): void
    {
        (new McpTokenAudienceVerifier(new McpResourceUri(self::RESOURCE_URI)))->verify($request);
    }

    /**
     * @param list<string> $audience
     */
    private function request(array $audience): Request
    {
        $builder = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedBy('https://api.example.test')
            ->identifiedBy('access-token-id');

        if ([] !== $audience) {
            $builder = $builder->permittedFor(...$audience);
        }

        return $this->requestWithToken(
            $builder->getToken(new Sha256(), InMemory::plainText(str_repeat('k', 32)))->toString(),
        );
    }

    private function requestWithToken(string $jwt): Request
    {
        return Request::create(
            '/api/v1/mcp-authoring',
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt],
        );
    }
}
