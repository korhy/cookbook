# The OAuth 2.1 authorization server

Cookbook hosts its own authorization server, built on **`league/oauth2-server-bundle` 2.0**, to
protect the MCP write path. It replaces the shared secret that `recipe_create` and
`recipe_import_from_url` take as a tool argument today — a token passed as an argument ends up
pasted in clear in the conversation, which is the whole reason for this work.

**This document covers phases 2 and 3: the server is installed, wired and now discoverable — and
nothing uses it yet.** The MCP write tools still go through `MCP_WRITE_TOKEN` and
`App\Service\Mcp\McpWriteGuard`, and they keep doing so until phase 5.

Related: [the client registration decision](../decisions/mcp-oauth-client-registration.md) ·
[where `aud` can be stamped](../decisions/mcp-oauth-audience-claim.md)

---

## What exists

| Endpoint | Security | Notes |
|---|---|---|
| `GET\|POST /oauth2/authorize` | `IS_AUTHENTICATED_REMEMBERED` | Bounces to the admin form login and back. The consent screen is phase 4 |
| `POST /oauth2/token` | none (`security: false`) | Must stay reachable with no session, or an expired client can never refresh |
| `POST /oauth2/device-code` | none | Declared by the bundle, inert: the device code grant is off, so league answers `unsupported_grant_type` |
| `GET /.well-known/oauth-authorization-server` | none (`security: false`) | RFC 8414 metadata — [below](#discovery) |
| `GET /.well-known/oauth-protected-resource[/api/v1/mcp]` | none (`security: false`) | RFC 9728 metadata — [below](#discovery) |

Grants enabled: **`authorization_code` + `refresh_token`, and nothing else.** Client credentials
defaults to *on* in the bundle and is explicitly turned off — it would issue an `mcp:write` token
with no human in the loop. Password, implicit and device code are off too.

One scope exists: **`mcp:write`**. PKCE is mandatory
(`require_code_challenge_for_public_clients: true`).

Lifetimes are stated explicitly rather than inherited: an access token lasts **one hour**, an
authorization code **ten minutes**, a refresh token **seven days** (the bundle's own default is a
month). So a client left idle for more than a week needs the Admin to log in and approve again —
deliberately, because the refresh token is the longest-lived credential in the design and it is
held by a public client with no secret to prove it is the same caller.

Four tables, created by `Version20261124130226`: `oauth2_client`, `oauth2_authorization_code`,
`oauth2_access_token`, `oauth2_refresh_token`.

---

## Discovery

Two documents make the server findable, so a client needs no URL configured by hand. Both are built
by `App\OAuth\DiscoveryMetadataProvider`, served by `App\Controller\OAuthMetadataController`, and
reachable anonymously — a client discovers the server *before* it holds any credential, so a
redirect to the login form would leave it with no way to recover.

| Path | RFC | Members |
|---|---|---|
| `/.well-known/oauth-authorization-server` | 8414 | `issuer`, `authorization_endpoint`, `token_endpoint`, `response_types_supported`, `grant_types_supported`, `token_endpoint_auth_methods_supported`, `code_challenge_methods_supported`, `scopes_supported` |
| `/.well-known/oauth-protected-resource/api/v1/mcp` | 9728 | `resource`, `authorization_servers`, `scopes_supported`, `bearer_methods_supported` |
| `/.well-known/oauth-protected-resource` | 9728 | the same document, on purpose — see below |

**Nothing in either document is written by hand.** The endpoint paths come from the router; the
grants and the scopes are read back out of the bundle's own container definitions by
`App\DependencyInjection\Compiler\OAuthDiscoveryPass`, because `league_oauth2_server.yaml` is the
only place that decides them and neither value is exposed as a parameter. Switch a grant off there
and the document stops advertising it — and `OAuthMetadataTest` fails, which is the intended way to
find out.

Two values are fixed rather than derived, both for a stated reason:
`token_endpoint_auth_methods_supported` is `["none"]` because the one client is public and holds no
secret, and `code_challenge_methods_supported` is `["S256"]` — never `plain`, which would put the
verifier where an interceptor can read it.

**There is no `registration_endpoint`.** Registration is pre-registration only
([the phase 0 decision](../decisions/mcp-oauth-client-registration.md)), and advertising an
endpoint that does not exist would send a client down a flow that 404s.

### `MCP_RESOURCE_URI` — the one value everything hangs off

The canonical URI of the MCP resource server, reaching the application as the
`app.mcp_resource_uri` parameter. `.env` carries the production value, `.env.dev` and `.env.test`
override it for those environments, and a real environment variable (Coolify) wins over all three.

Three mechanisms compare it **by exact string equality**, so they have to agree byte for byte:

1. the `resource` member of the RFC 9728 document, which the client reads;
2. the `aud` claim stamped on every access token (below);
3. the RFC 8707 audience validation of phase 5.

Hence the rules, enforced in the provider's constructor rather than left to a comment: an absolute
`http(s)` URL of `/api/v1/mcp`, no query, no fragment, no credentials, **no trailing slash**. A
malformed value throws on the first request that needs it, because the alternative is a server that
rejects every token with nothing in the logs that says why.

The **issuer is derived from it**: the same URL with its path dropped. The authorization server and
the resource server are the same application, and RFC 8414 locates an issuer's metadata at
`<issuer>/.well-known/oauth-authorization-server` — a document served from the root therefore
describes an issuer with no path. Deriving it removes the one way these two documents could
contradict each other.

### Why the bare `/.well-known/oauth-protected-resource` is served as well

RFC 9728 §3.1 inserts the resource's path after the well-known segment when the resource
identifier has one, which gives the canonical `/.well-known/oauth-protected-resource/api/v1/mcp`.
The bare form is served too because it is what a client that treats the resource identifier as an
origin will try, and this application hosts exactly one protected resource — there is no second
document the bare path could be confused with, and `resource` names the MCP endpoint either way.

Any **other** path (`/.well-known/oauth-protected-resource/api/v1/recipes`, say) is a 404. It asks
about a resource we do not protect, and answering would tell a client this document describes it.

### The `aud` claim

`App\OAuth\AudienceStampingAccessTokenRepository` decorates
`league.oauth2_server.repository.access_token` so that every issued access token names the MCP
endpoint in its audience. league ships no RFC 8707 support and hard-codes the *client* identifier
as `aud`, so without this there is nothing for phase 5 to validate. The full reasoning, including
the three hooks that do not work, is in
[the audience decision](../decisions/mcp-oauth-audience-claim.md).

The claim is an **array of two** — `["<resource uri>", "<client id>"]` — because lcobucci's
`permittedFor()` appends with dedup instead of replacing, so league's own call adds to ours. RFC
7519 §4.1.3 allows that and a resource server validates by membership.

### The `WWW-Authenticate` challenge — written, not wired

`App\Security\McpBearerChallenge` answers `401` with
`WWW-Authenticate: Bearer …, resource_metadata="<the RFC 9728 URL>"`, and `403` with
`error="insufficient_scope"`. That `resource_metadata` pointer is what gets a client from one
failed request to the authorization server.

**Nothing calls it yet, and that is deliberate.** Phase 5 creates the `mcp_authoring` firewall and
attaches it as both `entry_point` and `access_denied_handler`; wiring it earlier would change how
`/api/v1/mcp` answers today. `tests/Security/McpBearerChallengeTest.php` is what keeps it honest in
the meantime — it is not dead code.

---

## Keys

The authorization server has **its own RSA keypair in `config/oauth/`**, deliberately separate from
Lexik's `config/jwt/`. The two have different blast radii: regenerating `config/oauth` invalidates
every MCP access and refresh token, regenerating `config/jwt` logs out Radiant. Keeping them apart
means neither operation forces the other.

```bash
make oauth-keys
```

`--skip-if-exists`, so it is safe to re-run. `make up` does the same thing on first boot
(`docker/frankenphp/dev-entrypoint.sh`), and CI generates a throwaway pair before PHPUnit.

Two secrets go in **`.env.local`** (git-ignored, edited by hand — `.env` carries empty defaults):

```dotenv
OAUTH_PASSPHRASE=<the private key passphrase>
OAUTH_ENCRYPTION_KEY=<openssl rand -hex 32>
```

**Set `OAUTH_PASSPHRASE` before generating the keypair.** The passphrase is baked into the private
key at generation time, so changing it afterwards means regenerating the pair — and that
invalidates every issued token.

`OAUTH_ENCRYPTION_KEY` encrypts the authorization codes and refresh tokens league hands to the
client. Both values are resolved at runtime, so the container still boots with them empty; the
failure surfaces on the first token request instead.

### Production — not configured yet (phase 7)

> **`config/oauth/` must be a persistent volume or the keys must come from Coolify, before this
> server is used in production.**

`var/` is not persistent and the container is rebuilt on every deploy. If the keypair is generated
at boot into the image's own filesystem, **every container restart issues a fresh keypair and
invalidates every token that was ever handed out** — the same trap the thumbnails volume and
`config/jwt` already exist to avoid (see [the deploy guide](../deploy/coolify.md)).

For that reason `docker/frankenphp/entrypoint.prod.sh` is **deliberately not** given an
`ensure_oauth_keypair` step in this phase. Generating keys unattended is only correct once the
volume is there. Phase 7 adds the volume, the two environment variables and the entrypoint step
together.

---

## The pre-registered client

Client registration is pre-registration only — no DCR endpoint, no CIMD resolver. The reasoning is
in [the phase 0 decision](../decisions/mcp-oauth-client-registration.md).

The client is **public** (no secret), so PKCE is the only proof of possession:

```bash
make console C="league:oauth2-server:create-client --public --redirect-uri='http://localhost:3118/callback' --grant-type=authorization_code --grant-type=refresh_token --scope=mcp:write 'Cookbook MCP authoring client' cookbook-mcp-authoring"
```

`cookbook-mcp-authoring` is the `client_id`. It has to fit `oauth2_client.identifier VARCHAR(32)`.

To inspect or change it later:

```bash
make console C="league:oauth2-server:list-clients"
```

```bash
make console C="league:oauth2-server:update-client cookbook-mcp-authoring --redirect-uri='http://localhost:3118/callback'"
```

**Never pass `--allow-plain-text-pkce`.** It permits `code_challenge_method=plain`, which sends the
verifier where an interceptor can read it and defeats the point of PKCE on a public client.

### Why that redirect URI, and why the port is pinned

league matches redirect URIs by **exact string**, so the registered value has to be byte-identical
to what the client sends. Claude Code builds it as `http://localhost:<port>/callback`, and **picks
`<port>` at random from the ephemeral range** unless told otherwise — which is exactly why
`claude mcp add` has a `--callback-port` flag, described in its own help as being "for servers
requiring pre-registered redirect URIs".

`3118` is Claude Code's own built-in fallback port, so it is the natural value to pin. Both facts
are read off the installed binary (`~/.local/share/claude/versions/2.1.331`), which contains
`function Yns(n=f){return `http://localhost:${n}/callback`}` next to `var f=3118` and an
`MCP_OAUTH_CALLBACK_PORT` lookup.

If a future client version changes the path, **read the `redirect_uri` off the first
`/oauth2/authorize` request and register that verbatim** rather than guessing — a mismatch here
fails the flow with `invalid_client` and no useful hint.

### Wiring a client (phase 5 onward)

Once the write path actually consumes the token, the server is added like this:

```bash
claude mcp add --transport http cookbook https://api.clementboudinel.fr/api/v1/mcp --client-id cookbook-mcp-authoring --callback-port 3118
```

No `--client-secret`: the client is public. The first call opens the browser at
`/oauth2/authorize`, the admin logs in with the existing form login, and the token comes back to
`http://localhost:3118/callback`.

---

## What is not here yet

| Phase | Missing piece |
|---|---|
| 4 | The consent page on `/oauth2/authorize` |
| 5 | The `mcp_authoring` firewall, `McpScopeVoter`, wiring `McpBearerChallenge`, the RFC 8707 audience validation, and dropping `string $token` from the two write tools |
| 6 | End-to-end tests of the whole flow |
| 7 | The production volume for `config/oauth/`, the Coolify environment variables, CORS on `/.well-known` and `/oauth2`, and `trusted_proxies` |

**Phase 7 has to land before this server is used in production**, and that is now more pressing
than it was: these documents publish a `token_endpoint` that production cannot yet mint a token
from, because `config/oauth/` is not a persistent volume and the entrypoint deliberately generates
no keypair. Discovery works; the flow behind it does not, until the volume exists.
