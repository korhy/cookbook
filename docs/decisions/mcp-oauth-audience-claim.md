# MCP OAuth — where the `aud` claim can be stamped

**Date:** 2026-11-24 · **Status:** accepted · **Scope:** the OAuth 2.1 authorization server
protecting the MCP write path

## The question

The MCP authorization specification (revision **2026-07-28**) requires the resource server to
reject a token that was not issued for it:

> MCP servers MUST validate that access tokens were issued specifically for them.

The mechanism the spec points at is RFC 8707 resource indicators: the client sends
`resource=<canonical MCP endpoint URI>` on the authorization and token requests, and the
authorization server stamps that value into the token's `aud` claim.

**`league/oauth2-server` 9.5.0 does not implement RFC 8707.** Grepping its `src/` for `resource`,
`resource_indicator` or `8707` returns nothing — the `resource` parameter is read nowhere and
discarded. So nothing writes an `aud` naming our endpoint, and the phase-3 validator has nothing to
check unless we put it there ourselves.

Three candidate hooks were read in the installed sources before choosing one.

## What the code actually does

### 1. `aud` is already set — to the wrong value

`League\OAuth2\Server\Entities\Traits\AccessTokenTrait::convertToJWT()` builds every access token
and hard-codes the audience:

```php
return $this->jwtConfiguration->builder()
    ->permittedFor($this->getClient()->getIdentifier())   // <- aud
    ->identifiedBy($this->getIdentifier())
    // …
```

So each token ships `aud: ["cookbook-mcp-authoring"]` — **the client**, not the resource server.
That is not what a resource server validates against, and it is not something we can simply
replace: `convertToJWT()` is `private` on the trait.

### 2. The extra-claims event cannot carry `aud`

The bundle has exactly the hook one would hope for.
`OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE` is dispatched from
`Repository\AccessTokenRepository::getNewToken()`, and whatever a listener puts in
`AccessTokenExtraClaimsResolveEvent::setExtraClaims()` reaches the JWT through
`Entity\AccessToken::withJwtBuilder()`:

```php
foreach ($this->extraClaims as $name => $value) {
    $builder = $builder->withClaim($name, $value);
}
```

**But `withClaim()` refuses registered claims.** `Lcobucci\JWT\Token\Builder::withClaim()`:

```php
if (in_array($name, RegisteredClaims::ALL, true)) {
    throw RegisteredClaimGiven::forClaim($name);
}
```

`aud` is in `RegisteredClaims::ALL`. A listener setting `['aud' => …]` throws at issuance rather
than producing a token. The event is the right hook for any *custom* claim and a dead end for this
one.

### 3. league's own `ACCESS_TOKEN_ISSUED` is a notification, not a mutation point

`RequestEvent::ACCESS_TOKEN_ISSUED` is emitted *after* `issueAccessToken()` in every grant
(`AuthCodeGrant.php:142`, `RefreshTokenGrant.php:87`, …). The entity is built and persisted by
then, and the bundle's `Entity\AccessToken` keeps `$extraClaims` private with no setter. Nothing
can be changed from there.

`league.oauth2_server.event.scope_resolve` only carries scopes, so it is not a claims hook either.

## Decision

**Stamp `aud` by decorating `AccessTokenRepositoryInterface`.**

`league.oauth2_server.repository.access_token` (defined in the bundle's
`config/access_token/default.php`) is an ordinary service behind an alias, so it decorates
normally. The decorator keeps `getNewToken()` and returns its own `AccessTokenEntityInterface`
that uses league's `AccessTokenTrait` and overrides the **public** `initJwtConfiguration()` to seed
the builder with the resource URI before `convertToJWT()` runs:

```php
$builder = $this->jwtConfiguration->builder()->permittedFor($this->resourceUri);
$this->jwtConfiguration = $this->jwtConfiguration->withBuilderFactory(static fn () => $builder);
```

Two things make this clean rather than a workaround:

- **It is the bundle's own idiom.** `Entity\AccessToken::initJwtConfiguration()` already overrides
  the builder factory exactly this way to inject its extra claims. We are using the seam the bundle
  itself uses, not fighting it.
- **`permittedFor()` appends, it does not replace.**
  `Lcobucci\JWT\Token\Builder::permittedFor()` merges with dedup (`array_diff` then `array_merge`),
  so league's own `->permittedFor($clientId)` adds to ours instead of overwriting it.

The resulting claim is therefore an **array of two**:

```json
"aud": ["https://api.clementboudinel.fr/api/v1/mcp", "cookbook-mcp-authoring"]
```

RFC 7519 §4.1.3 allows `aud` to be an array, and a resource server validates by membership, so the
extra client entry is harmless. Dropping it would mean abandoning `AccessTokenTrait` and
reimplementing `convertToJWT()` — more code owned by us, for no security gain.

## Consequences

- **Not implemented in phase 2.** Phase 2 installs and wires the authorization server; the
  audience check belongs with the resource-server validation and the `WWW-Authenticate` challenge
  in phase 3, which is where the decorator and its test land.
- The canonical resource URI becomes a single piece of configuration, shared by the decorator and
  by the RFC 9728 metadata document — they must agree exactly or the validation rejects every
  token.
- The write path keeps working throughout: `MCP_WRITE_TOKEN` and `McpWriteGuard` are untouched
  until phase 5 retires them.
- Reproducing the findings above: the relevant files are
  `vendor/league/oauth2-server/src/Entities/Traits/AccessTokenTrait.php`,
  `vendor/league/oauth2-server-bundle/src/{Entity/AccessToken.php,Repository/AccessTokenRepository.php}`
  and `vendor/lcobucci/jwt/src/Token/Builder.php`.
