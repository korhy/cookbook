<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use League\Bundle\OAuth2ServerBundle\Manager\ScopeManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\DeviceCodeGrant;
use League\OAuth2\Server\Grant\ImplicitGrant;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Turns the authorization server's *real* configuration into the two parameters the RFC 8414
 * metadata document is built from.
 *
 * The point is that the document cannot drift. `league_oauth2_server.yaml` enables grants and
 * declares scopes, and a document repeating those values by hand would keep advertising a grant
 * someone had just switched off — a client would then attempt a flow the token endpoint answers
 * with `unsupported_grant_type`. So both values are read back out of the container definitions the
 * bundle's extension has already built:
 *
 * - **Grants.** `LeagueOAuth2ServerExtension::configureAuthorizationServer()` adds one
 *   `enableGrantType` method call per enabled grant to the `AuthorizationServer` definition, and
 *   adds nothing for a disabled one. Reading those calls back is the only way to see the decision:
 *   the extension sets no parameter for it, and `AuthorizationServer::$enabledGrantTypes` is
 *   private.
 * - **Scopes.** `configureScopes()` adds one `save` call per *available* scope to the
 *   `ScopeManagerInterface` definition. (`league.oauth2_server.scopes.default` is a parameter, but
 *   it holds the scopes granted when a client names none — not the set the server supports.)
 *
 * Both lists are sorted, so the published document does not depend on the order the extension
 * happens to evaluate its own configuration keys in.
 *
 * Registered from App\Kernel::build().
 */
final class OAuthDiscoveryPass implements CompilerPassInterface
{
    public const GRANT_TYPES_PARAMETER = 'app.oauth_grant_types_supported';

    public const SCOPES_PARAMETER = 'app.oauth_scopes_supported';

    /**
     * league's grant classes mapped to the `grant_type` values RFC 6749 and RFC 8628 give them.
     *
     * Every grant league ships is listed, including the ones that are off: the pass throws on an
     * unknown one rather than skipping it, so a bundle upgrade that adds a grant fails the build
     * instead of quietly publishing an incomplete document.
     *
     * @var array<class-string, non-empty-string>
     */
    private const GRANT_TYPES = [
        AuthCodeGrant::class => 'authorization_code',
        RefreshTokenGrant::class => 'refresh_token',
        ClientCredentialsGrant::class => 'client_credentials',
        PasswordGrant::class => 'password',
        ImplicitGrant::class => 'implicit',
        DeviceCodeGrant::class => 'urn:ietf:params:oauth:grant-type:device_code',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(AuthorizationServer::class)) {
            // The bundle is not loaded. Nothing in this project does that today, but a pass must
            // not be the thing that breaks the build if some environment ever stops loading it.
            return;
        }

        $grantTypes = $this->enabledGrantTypes($container);
        $scopes = $this->availableScopes($container);

        // An empty list means this pass stopped recognising how the bundle expresses its own
        // configuration — a release renaming `enableGrantType`, or moving to tagged services,
        // would leave both loops matching nothing. The chain does hold without this check
        // (OAuthMetadataTest asserts the whole document body, and `build-images` only runs after
        // a green CI, so nothing reaches the registry), but it would be found through a puzzling
        // assertion diff. Failing here names the cause instead.
        if ([] === $grantTypes || [] === $scopes) {
            throw new \LogicException(\sprintf('%s derived no %s from the OAuth bundle\'s configuration. The bundle most likely changed how it declares them, so the published metadata would be empty — read how its extension builds the AuthorizationServer and ScopeManager definitions, and update this pass.', self::class, [] === $grantTypes ? 'grant type' : 'scope'));
        }

        $container->setParameter(self::GRANT_TYPES_PARAMETER, $grantTypes);
        $container->setParameter(self::SCOPES_PARAMETER, $scopes);
    }

    /**
     * @return list<non-empty-string>
     */
    private function enabledGrantTypes(ContainerBuilder $container): array
    {
        $grantTypes = [];

        foreach ($container->findDefinition(AuthorizationServer::class)->getMethodCalls() as [$method, $arguments]) {
            if ('enableGrantType' !== $method || !($arguments[0] ?? null) instanceof Reference) {
                continue;
            }

            $grantClass = (string) $arguments[0];

            if (!isset(self::GRANT_TYPES[$grantClass])) {
                throw new \LogicException(\sprintf('Cannot build the OAuth authorization server metadata: the grant "%s" is enabled but has no `grant_type` name in %s::GRANT_TYPES. Add it there, so the published metadata keeps describing what the server actually accepts.', $grantClass, self::class));
            }

            $grantTypes[] = self::GRANT_TYPES[$grantClass];
        }

        sort($grantTypes);

        return array_values(array_unique($grantTypes));
    }

    /**
     * @return list<non-empty-string>
     */
    private function availableScopes(ContainerBuilder $container): array
    {
        $scopes = [];

        foreach ($container->findDefinition(ScopeManagerInterface::class)->getMethodCalls() as [$method, $arguments]) {
            if ('save' !== $method) {
                continue;
            }

            $scope = $arguments[0] ?? null;

            if (!$scope instanceof Definition) {
                continue;
            }

            $identifier = $scope->getArgument(0);

            if (\is_string($identifier) && '' !== $identifier) {
                $scopes[] = $identifier;
            }
        }

        sort($scopes);

        return array_values(array_unique($scopes));
    }
}
