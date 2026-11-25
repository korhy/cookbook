<?php

declare(strict_types=1);

namespace App\OAuth;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\ClaimsFormatter;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * An access token entity that names **the MCP authoring endpoint** in its `aud` claim, so the
 * resource server can tell a token minted for it apart from one minted for somebody else.
 *
 * league itself sets `aud` to the *client* identifier, hard-coded in
 * `AccessTokenTrait::convertToJWT()`, which is `private` and therefore not overridable. The seam
 * that is open is the **public** `initJwtConfiguration()`: `convertToJWT()` calls it and then asks
 * the configuration for a builder, so a configuration carrying a pre-seeded builder factory
 * decides what every token starts from. That is not a workaround — it is the same seam the
 * bundle's own `Entity\AccessToken` uses to inject its extra claims.
 *
 * The resulting claim is an **array of two, `[<client id>, <resource uri>]`, and that order is
 * load-bearing.** `League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator` reads the
 * *client identifier* back out of `$claims->get('aud')[0]` (BearerTokenValidator.php:137) — the
 * first entry, positionally, with no check of what it is. Seeding the resource URI first
 * therefore made every authenticated request report the resource URI as its client id: harmless
 * while nothing authenticated, wrong the moment the authoring firewall went live, since the audit
 * trail and the write path's rate-limit key are both built on that identity. Both entries are
 * seeded here instead, and league's own `permittedFor($clientId)` call then appends nothing —
 * `Lcobucci\JWT\Token\Builder::permittedFor()` merges with dedup (`array_diff` then
 * `array_merge`, Builder.php:42-48) rather than replacing.
 *
 * RFC 7519 §4.1.3 allows an array and a resource server validates by membership, so carrying the
 * client entry alongside ours costs nothing. Dropping it would mean abandoning `AccessTokenTrait`
 * and owning `convertToJWT()` ourselves, for no security gain.
 *
 * See docs/decisions/mcp-oauth-audience-claim.md for the hooks that were rejected first.
 */
final class AudienceAwareAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait {
        initJwtConfiguration as private leagueInitJwtConfiguration;
    }
    use EntityTrait;
    use TokenEntityTrait;

    /**
     * @param non-empty-string               $audience    the canonical resource URI this token is issued for
     * @param array<non-empty-string, mixed> $extraClaims claims from the bundle's ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE event
     */
    public function __construct(
        private readonly string $audience,
        private readonly array $extraClaims = [],
    ) {
    }

    public function initJwtConfiguration(): void
    {
        $this->leagueInitJwtConfiguration();

        // The client first, then us — see the class docblock: `aud[0]` is how league recovers the
        // client identifier. The client is already set by the time convertToJWT() runs, which is
        // the only caller of this method.
        $builder = $this->jwtConfiguration->builder()->permittedFor(
            $this->getClient()->getIdentifier(),
            $this->audience,
        );

        foreach ($this->extraClaims as $name => $value) {
            // Mirrors the bundle's own guard: `scopes` is written by convertToJWT() itself, and an
            // extra claim of that name would silently fight with it.
            if ('scopes' === $name) {
                throw new \InvalidArgumentException('The "scopes" claim is reserved and cannot be used as an extra claim.');
            }

            $builder = $builder->withClaim($name, $value);
        }

        $this->jwtConfiguration = $this->jwtConfiguration->withBuilderFactory(
            static fn (ClaimsFormatter $claimsFormatter): Builder => $builder
        );
    }
}
