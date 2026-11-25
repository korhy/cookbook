<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The canonical URI of the MCP resource server, with its invariants enforced once.
 *
 * This exists as a type rather than a string because **three separate mechanisms compare the value
 * by exact string equality** — the `resource` member of the RFC 9728 document, the `aud` claim
 * stamped on every access token, and phase 5's RFC 8707 audience validation — and a value that is
 * wrong in any of the ways below does not fail usefully. It rejects every token, with nothing in
 * the logs that says why. An empty value is worse still: `permittedFor('')` writes an empty
 * audience into a perfectly valid-looking JWT without complaining.
 *
 * Validating it in whichever class happens to be asked first is not enough, which is how this
 * class came to exist: the metadata provider checked the value while the token-issuing decorator
 * took the raw parameter, so a malformed configuration made the documents answer 500 while tokens
 * kept being minted with a broken audience. Both now depend on this, so no path reads the value
 * without its invariants — and the token path does not have to depend on a class whose job is
 * building an HTTP document in order to get them.
 */
final readonly class McpResourceUri
{
    /**
     * The origin: the resource URI with its path dropped.
     *
     * The authorization server and the resource server are the same application, and RFC 8414
     * locates an issuer's metadata at `<issuer>/.well-known/oauth-authorization-server` — so a
     * document served from the root describes an issuer with no path component. Deriving it here
     * instead of configuring it separately removes the one way the two documents could contradict
     * each other.
     */
    private string $issuer;

    private string $path;

    public function __construct(
        #[Autowire(param: 'app.mcp_resource_uri')]
        private string $value,
    ) {
        $parts = parse_url($this->value);

        if (false === $parts
            || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || str_ends_with($this->value, '/')
        ) {
            throw new \LogicException(\sprintf('MCP_RESOURCE_URI must be the absolute http(s) URL of the MCP endpoint, with no query, no fragment and no trailing slash — got "%s".', $this->value));
        }

        $this->issuer = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $this->path = $parts['path'] ?? '';
    }

    /**
     * The value a client sends as its `resource` parameter. Never empty: the constructor requires
     * a scheme and a host, so there is no accepted value shorter than `http://h`.
     *
     * @return non-empty-string
     */
    public function value(): string
    {
        \assert('' !== $this->value);

        return $this->value;
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    /**
     * The path component, which RFC 9728 §3.1 appends to the well-known segment. Empty when the
     * resource sits at the origin — which the RFC allows and this project does not use.
     */
    public function path(): string
    {
        return $this->path;
    }
}
