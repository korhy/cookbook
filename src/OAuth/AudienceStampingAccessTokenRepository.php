<?php

declare(strict_types=1);

namespace App\OAuth;

use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Makes every access token this server issues carry the MCP endpoint in its `aud` claim.
 *
 * `league/oauth2-server` 9.5 does not implement RFC 8707 resource indicators — the `resource`
 * parameter a client sends is read nowhere — so nothing would otherwise write an audience naming
 * our endpoint, and phase 5's validation would have nothing to check. Decorating the repository is
 * the one hook that can: it is where the entity is built, and the entity decides its own claims.
 * The alternatives (the extra-claims event, league's `ACCESS_TOKEN_ISSUED`) were read and rejected
 * in docs/decisions/mcp-oauth-audience-claim.md.
 *
 * Only `getNewToken()` behaves differently; persistence and revocation are the decorated
 * repository's business and are passed straight through.
 *
 * **The audience is a constant, not the client's `resource` parameter.** This server hosts exactly
 * one protected resource, so there is nothing for a caller to choose, and taking the value from
 * the request would let a caller mint a token for an audience of their choosing.
 */
#[AsDecorator('league.oauth2_server.repository.access_token')]
final readonly class AudienceStampingAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private AccessTokenRepositoryInterface $decorated,
        private EventDispatcherInterface $eventDispatcher,
        // The validated value object, never the raw `app.mcp_resource_uri` parameter. Taking the
        // parameter directly is what this class used to do, and it was wrong in a way that only
        // showed on the quiet side: a malformed value made the metadata documents answer 500
        // while this path kept minting tokens, and an empty one stamped an empty `aud` through
        // `permittedFor('')` without raising anything at all.
        private McpResourceUri $resourceUri,
    ) {
    }

    /**
     * Mirrors `League\Bundle\OAuth2ServerBundle\Repository\AccessTokenRepository::getNewToken()`
     * rather than delegating to it, because the entity that method returns is `final` and keeps
     * its claims private — there is no way to add an audience to one after the fact.
     *
     * The extra-claims event is dispatched here for that same reason: it is a documented hook of
     * the bundle, and skipping it would silently break any listener someone adds later.
     *
     * @param ScopeEntityInterface[] $scopes
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $event = new AccessTokenExtraClaimsResolveEvent($clientEntity, $scopes, $userIdentifier);
        $this->eventDispatcher->dispatch($event, OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE);

        $accessToken = new AudienceAwareAccessToken($this->resourceUri->value(), $event->getExtraClaims());
        $accessToken->setClient($clientEntity);

        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $accessToken->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->decorated->persistNewAccessToken($accessTokenEntity);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->decorated->revokeAccessToken($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->decorated->isAccessTokenRevoked($tokenId);
    }
}
