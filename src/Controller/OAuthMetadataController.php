<?php

declare(strict_types=1);

namespace App\Controller;

use App\OAuth\DiscoveryMetadataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the OAuth discovery documents. Both bodies are built by
 * {@see DiscoveryMetadataProvider} — there is nothing to decide here but the HTTP shape.
 *
 * Neither path is behind security: `security.yaml`'s `oauth2_public` firewall covers
 * `^/\.well-known/` with `security: false`, because a client discovers the server *before* it
 * holds any credential, and a 302 to the login form would leave it with no way to recover.
 */
final class OAuthMetadataController extends AbstractController
{
    #[Route(
        path: '/.well-known/oauth-authorization-server',
        name: 'oauth2_metadata_authorization_server',
        methods: ['GET'],
    )]
    public function authorizationServer(DiscoveryMetadataProvider $metadata): JsonResponse
    {
        return new JsonResponse($metadata->authorizationServerMetadata());
    }

    /**
     * One route for both forms RFC 9728 §3.1 can produce. `{resourcePath}` matches the resource's
     * own path when the identifier has one (`/.well-known/oauth-protected-resource/api/v1/mcp`)
     * and is empty for the bare form; the provider decides which paths this server actually hosts
     * a resource for, and anything else gets a 404 instead of a document about a resource that is
     * not here.
     */
    #[Route(
        path: '/.well-known/oauth-protected-resource{resourcePath}',
        name: 'oauth2_metadata_protected_resource',
        requirements: ['resourcePath' => '.*'],
        defaults: ['resourcePath' => ''],
        methods: ['GET'],
    )]
    public function protectedResource(string $resourcePath, DiscoveryMetadataProvider $metadata): JsonResponse
    {
        if (!$metadata->describesResourcePath($resourcePath)) {
            throw $this->createNotFoundException(\sprintf('No protected resource is hosted at "%s".', $resourcePath));
        }

        return new JsonResponse($metadata->protectedResourceMetadata());
    }
}
