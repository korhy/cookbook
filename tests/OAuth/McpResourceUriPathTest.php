<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\OAuth\McpResourceUri;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Ties the canonical resource URI to the endpoint that actually exists.
 *
 * The authoring path is written in four places that have no way of checking each other:
 * `http.path` in `config/packages/mcp.yaml`, the firewall `pattern` and the `access_control` rule
 * in `security.yaml`, and `MCP_RESOURCE_URI`. If the resource URI and the route disagree, nothing
 * breaks loudly — the metadata document advertises one URL, the challenge points a client at it,
 * the client asks for a token for it, and that token is then compared by exact equality against a
 * resource nobody serves. Every request is refused and no log line says why.
 *
 * Symfony has no mechanism that would catch this, so it is asserted here instead.
 */
final class McpResourceUriPathTest extends KernelTestCase
{
    public function testTheResourceUriPathIsTheAuthoringEndpointsRoute(): void
    {
        self::bootKernel();

        $resourceUri = static::getContainer()->get(McpResourceUri::class);
        \assert($resourceUri instanceof McpResourceUri);

        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        // The MCP bundle names its HTTP routes `_mcp_endpoint_<server name>`; `authoring` is the
        // server declared in config/packages/mcp.yaml with the write tools in its registry.
        $route = $router->getRouteCollection()->get('_mcp_endpoint_authoring');

        self::assertNotNull($route, 'The authoring MCP server publishes no HTTP route.');
        self::assertSame($route->getPath(), $resourceUri->path());
    }

    /**
     * The resource identifier must not be the *public* endpoint. It would be self-consistent —
     * the documents, the `aud` claim and the check would all agree on it — and still wrong: a
     * client would be told to get a token for a URL it never calls, and the 401 it followed to
     * get there would have come from somewhere else. Naming the protected endpoint is what makes
     * the discovery chain walkable.
     */
    public function testTheResourceUriIsNotThePublicReadEndpoint(): void
    {
        self::bootKernel();

        $resourceUri = static::getContainer()->get(McpResourceUri::class);
        \assert($resourceUri instanceof McpResourceUri);

        $publicRoute = static::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('_mcp_endpoint_cookbook');

        self::assertNotNull($publicRoute);
        self::assertNotSame($publicRoute->getPath(), $resourceUri->path());
    }
}
