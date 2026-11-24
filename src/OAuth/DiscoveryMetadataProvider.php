<?php

declare(strict_types=1);

namespace App\OAuth;

use App\DependencyInjection\Compiler\OAuthDiscoveryPass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the two discovery documents an MCP client reads before it can ask for a token:
 *
 * - **RFC 8414** authorization server metadata, served at `/.well-known/oauth-authorization-server`
 *   — what this server is and which flows it accepts.
 * - **RFC 9728** protected resource metadata, served under `/.well-known/oauth-protected-resource`
 *   — which authorization server guards the MCP endpoint.
 *
 * Both are *derived*, never written out by hand, because they are a contract parsed by software we
 * do not control: the endpoint paths come from the router, the grants and scopes from the
 * container parameters {@see OAuthDiscoveryPass} reads off the bundle's own definitions, and every
 * absolute URL from the one configured resource URI.
 *
 * **The issuer is the origin of that resource URI**, with its path dropped. The authorization
 * server and the resource server are the same Symfony application here, and RFC 8414 locates an
 * issuer's metadata at `<issuer>/.well-known/oauth-authorization-server` — a document served from
 * the root therefore describes an issuer with no path component. Deriving it instead of
 * configuring it separately removes the one way these documents could contradict each other.
 */
final class DiscoveryMetadataProvider
{
    /**
     * S256 only, and deliberately not `plain`.
     *
     * The MCP authorization specification requires an authorization server to support S256, and
     * league supports it unconditionally. `plain` is a per-client switch
     * (`--allow-plain-text-pkce`, which this project never passes) and advertising it would invite
     * a client to send the verifier where an interceptor can read it — which is the whole point of
     * PKCE on a public client.
     */
    private const CODE_CHALLENGE_METHODS_SUPPORTED = ['S256'];

    /**
     * The one authentication method the token endpoint accepts.
     *
     * `none` is RFC 8414's name for a public client authenticating with no credentials — which is
     * what ours is: a CLI on an operator's machine cannot keep a secret, so PKCE is the only proof
     * of possession. See docs/decisions/mcp-oauth-client-registration.md.
     */
    private const TOKEN_ENDPOINT_AUTH_METHODS_SUPPORTED = ['none'];

    /**
     * How a client may present the access token. The header, and nothing else: RFC 6750's query
     * parameter form puts the credential in access logs, and its form-body form cannot carry one
     * on a GET.
     */
    private const BEARER_METHODS_SUPPORTED = ['header'];

    private readonly string $issuer;

    private readonly string $resourcePath;

    /**
     * @param list<string> $grantTypesSupported
     * @param list<string> $scopesSupported
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(param: 'app.mcp_resource_uri')]
        private readonly string $resourceUri,
        #[Autowire(param: OAuthDiscoveryPass::GRANT_TYPES_PARAMETER)]
        private readonly array $grantTypesSupported,
        #[Autowire(param: OAuthDiscoveryPass::SCOPES_PARAMETER)]
        private readonly array $scopesSupported,
    ) {
        $parts = parse_url($this->resourceUri);

        // A misconfigured MCP_RESOURCE_URI is not a cosmetic problem: the value ends up in the
        // `aud` claim and in the published document, both compared by exact string equality, so a
        // stray trailing slash rejects every token with nothing in the logs to explain it. Fail
        // here instead, on the first request that needs the value.
        if (false === $parts
            || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || str_ends_with($this->resourceUri, '/')
        ) {
            throw new \LogicException(\sprintf('MCP_RESOURCE_URI must be the absolute http(s) URL of the MCP endpoint, with no query, no fragment and no trailing slash — got "%s".', $this->resourceUri));
        }

        $this->issuer = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $this->resourcePath = $parts['path'] ?? '';
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function resourceUri(): string
    {
        return $this->resourceUri;
    }

    /**
     * RFC 8414 §2 — the authorization server metadata document.
     *
     * @return array{
     *     issuer: string,
     *     authorization_endpoint: string,
     *     token_endpoint: string,
     *     response_types_supported: list<string>,
     *     grant_types_supported: list<string>,
     *     token_endpoint_auth_methods_supported: list<string>,
     *     code_challenge_methods_supported: list<string>,
     *     scopes_supported: list<string>,
     * }
     */
    public function authorizationServerMetadata(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->absoluteUrl('oauth2_authorize'),
            'token_endpoint' => $this->absoluteUrl('oauth2_token'),
            'response_types_supported' => $this->responseTypesSupported(),
            'grant_types_supported' => $this->grantTypesSupported,
            'token_endpoint_auth_methods_supported' => self::TOKEN_ENDPOINT_AUTH_METHODS_SUPPORTED,
            'code_challenge_methods_supported' => self::CODE_CHALLENGE_METHODS_SUPPORTED,
            'scopes_supported' => $this->scopesSupported,
        ];
    }

    /**
     * RFC 9728 §2 — the protected resource metadata document for the MCP endpoint.
     *
     * @return array{
     *     resource: string,
     *     authorization_servers: list<string>,
     *     scopes_supported: list<string>,
     *     bearer_methods_supported: list<string>,
     * }
     */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->resourceUri,
            'authorization_servers' => [$this->issuer],
            'scopes_supported' => $this->scopesSupported,
            'bearer_methods_supported' => self::BEARER_METHODS_SUPPORTED,
        ];
    }

    /**
     * The absolute URL of the document above — what a `WWW-Authenticate` challenge points at
     * through its `resource_metadata` parameter, and what RFC 9728 §3.1 calls the canonical
     * location: the resource's own path inserted after the well-known segment.
     */
    public function protectedResourceMetadataUrl(): string
    {
        return $this->absoluteUrl('oauth2_metadata_protected_resource', ['resourcePath' => $this->resourcePath]);
    }

    /**
     * Whether this server should answer with the protected resource document for the path
     * suffixing the well-known URL.
     *
     * Two forms are accepted. The canonical one carries the resource's path
     * (`/.well-known/oauth-protected-resource/api/v1/mcp`); the **bare** one carries nothing
     * (`/.well-known/oauth-protected-resource`) and is served on purpose. It is what a client that
     * treats the resource identifier as an origin will try, and since this application hosts
     * exactly one protected resource there is no second document the bare path could be confused
     * with — the `resource` member names the MCP endpoint either way. Any other path is a question
     * about a resource we do not host, and gets a 404 rather than a document that would be wrong.
     */
    public function describesResourcePath(string $resourcePath): bool
    {
        return '' === $resourcePath || $resourcePath === $this->resourcePath;
    }

    /**
     * @return list<string>
     */
    public function scopesSupported(): array
    {
        return $this->scopesSupported;
    }

    /**
     * Derived from the enabled grants rather than stated: `code` is the authorization code grant's
     * response type and `token` the implicit grant's, so turning a grant off in
     * `league_oauth2_server.yaml` stops it being advertised here too.
     *
     * @return list<string>
     */
    private function responseTypesSupported(): array
    {
        $responseTypes = [];

        if (\in_array('authorization_code', $this->grantTypesSupported, true)) {
            $responseTypes[] = 'code';
        }

        if (\in_array('implicit', $this->grantTypesSupported, true)) {
            $responseTypes[] = 'token';
        }

        return $responseTypes;
    }

    /**
     * The route's path from the router, the origin from the configured resource URI.
     *
     * Not `UrlGeneratorInterface::ABSOLUTE_URL`: that takes its host from the incoming request (or
     * from DEFAULT_URI on the command line), which would publish a document whose endpoints and
     * whose issuer disagree the moment the app is reached on another hostname.
     *
     * @param array<string, string> $parameters
     */
    private function absoluteUrl(string $route, array $parameters = []): string
    {
        return $this->issuer.$this->urlGenerator->generate($route, $parameters);
    }
}
