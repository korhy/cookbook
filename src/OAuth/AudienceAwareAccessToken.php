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
 * An access token entity that names **the MCP endpoint** in its `aud` claim, so a resource server
 * can tell a token minted for it apart from one minted for somebody else.
 *
 * league itself sets `aud` to the *client* identifier, hard-coded in
 * `AccessTokenTrait::convertToJWT()`, which is `private` and therefore not overridable. The seam
 * that is open is the **public** `initJwtConfiguration()`: `convertToJWT()` calls it and then asks
 * the configuration for a builder, so a configuration carrying a pre-seeded builder factory
 * decides what every token starts from. That is not a workaround — it is the same seam the
 * bundle's own `Entity\AccessToken` uses to inject its extra claims.
 *
 * The resulting claim is an **array of two**, `[<resource uri>, <client id>]`:
 * `Lcobucci\JWT\Token\Builder::permittedFor()` merges with dedup (`array_diff` then `array_merge`,
 * Builder.php:42-48), so league's own call appends to ours instead of replacing it. RFC 7519
 * §4.1.3 allows an array, and a resource server validates by membership, so the client entry is
 * harmless. Dropping it would mean abandoning `AccessTokenTrait` and owning `convertToJWT()`
 * ourselves, for no security gain.
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

        $builder = $this->jwtConfiguration->builder()->permittedFor($this->audience);

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
