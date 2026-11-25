<?php

declare(strict_types=1);

namespace App\Security;

use App\OAuth\McpResourceUri;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use League\Bundle\OAuth2ServerBundle\Security\Exception\OAuth2AuthenticationFailedException;
use Symfony\Component\HttpFoundation\Request;

/**
 * RFC 8707 audience validation: refuses an access token that was not issued for **this** resource.
 *
 * This is an obligation the MCP authorization specification puts on a resource server, and it is
 * the half of the design that `league/oauth2-server` does not do for us. Its
 * `BearerTokenValidator` checks the signature, the validity window and revocation — it never looks
 * at `aud`. So without this class, any token this authorization server ever issued would open the
 * write path, including one minted for a different protected resource. Once a second resource
 * exists behind the same issuer, that is a confused-deputy hole; checking now is what keeps adding
 * one from being a security change.
 *
 * The comparison is **exact string equality against the one canonical URI**, which is why
 * {@see McpResourceUri} is a validated value object rather than a parameter: the same string has
 * to come out of the RFC 9728 document, out of the `aud` stamped at issuing time and out of this
 * check, and a near-miss (a trailing slash, a different scheme) is indistinguishable from an
 * attack.
 *
 * **On parsing the token a second time.** This runs from `CheckPassportEvent`, which
 * `AuthenticatorManager` dispatches *after* `OAuth2Authenticator::authenticate()` has already put
 * the JWT through the resource server. The signature and the expiry are therefore settled before
 * we get here, and the parse below only reads a claim the bundle discards: of the five it keeps,
 * `aud` is not one. Verifying the signature again would be redundant, and relying on the
 * bundle's `oauth_client_id` attribute instead is not an option — it is `aud[0]`, not an
 * independent claim.
 */
final readonly class McpTokenAudienceVerifier
{
    public function __construct(private McpResourceUri $resourceUri)
    {
    }

    /**
     * @throws OAuth2AuthenticationFailedException when the token names another audience, or none
     */
    public function verify(Request $request): void
    {
        $audience = $this->audienceOf($this->bearerTokenOf($request));

        if (!\in_array($this->resourceUri->value(), $audience, true)) {
            // No detail about what WAS in `aud`: the caller is unauthenticated at this point, and
            // the audiences this server issues for are not theirs to enumerate. The challenge
            // this turns into points at the RFC 9728 document, which names the audience a token
            // for this endpoint needs — the legitimate way to find it out.
            throw OAuth2AuthenticationFailedException::create('The access token was not issued for this resource.');
        }
    }

    /**
     * @return list<string>
     */
    private function audienceOf(string $jwt): array
    {
        try {
            $token = (new Parser(new JoseEncoder()))->parse($jwt);
        } catch (\Throwable $e) {
            // Unreachable in practice — the resource server parsed the same string a moment ago —
            // but an unparseable token must not become an *authorized* one by way of an
            // uncaught error, so it is refused explicitly.
            throw OAuth2AuthenticationFailedException::create('The access token could not be read.', $e);
        }

        if (!$token instanceof UnencryptedToken) {
            throw OAuth2AuthenticationFailedException::create('The access token could not be read.');
        }

        // Deliberately NOT annotated as a list. RFC 7519 §4.1.3 allows `aud` to be a string or
        // an array, and this is a claim out of a token the server may not have minted — a JSON
        // object would decode to string keys. The normalisation below is what makes the
        // comparison in verify() a plain in_array over values.
        /** @var array<array-key, string>|string $audience */
        $audience = $token->claims()->get(RegisteredClaims::AUDIENCE, []);

        return \is_string($audience) ? [$audience] : array_values($audience);
    }

    private function bearerTokenOf(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');

        // The same extraction league performs, kept in step with it on purpose: a mismatch here
        // would validate the audience of one string while authenticating another.
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', $header));

        if ('' === $jwt) {
            throw OAuth2AuthenticationFailedException::create('The access token is missing.');
        }

        return $jwt;
    }
}
