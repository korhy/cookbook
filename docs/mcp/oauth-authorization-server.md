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

`config/oauth/` holds **two** pairs, though: the `dev` one above, and `*_test.pem` for the test
environment (`make oauth-keys-test`, which `make phpunit` depends on). One pair cannot serve both —
`.env.local` is not loaded under `APP_ENV=test`, so the real passphrase cannot reach a test run,
and league's `CryptKey` decrypts in its constructor, which fails every test that touches the
authorization server with an opaque `Unable to read key from file`. The full reasoning is in
[the testing rules](../../.claude/rules/technical/testing.md).

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

### Production

`config/oauth` is a **Coolify persistent volume**, and `docker/frankenphp/entrypoint.prod.sh`
generates the keypair into it on the first boot — `ensure_oauth_keypair`, step 4 of the boot
sequence, running `league:oauth2-server:generate-keypair --skip-if-exists` with an
`APP_GENERATE_OAUTH_KEYS=0` off switch like every other step.

**The volume is what makes that step correct.** The container is rebuilt on every deploy, so
without one each restart would write a *new* keypair and invalidate every token ever handed out —
the trap the thumbnails volume and `config/jwt` already exist to avoid. `--skip-if-exists` is what
makes the second boot a no-op; the volume is what leaves something there to skip. Nothing announces
the failure either: the server answers normally and simply rejects every token older than the last
restart.

Two details the entrypoint handles that are easy to miss:

- It narrows both `.pem` files to **`0660`**. The bundle builds its `CryptKey` with
  `$keyPermissionsCheck = false`, and `Filesystem::dumpFile` leaves the pair at `0664`, so this
  line is the only thing keeping a private key from being world-readable inside the container.
- `config/oauth` is in **`.dockerignore`**, beside `config/jwt`. Without it a developer's local
  `private.pem` — generated with the passphrase from their own `.env.local` — would be baked into
  the image CI pushes to GHCR.

Two runtime environment variables in Coolify: **`OAUTH_PASSPHRASE`** and
**`OAUTH_ENCRYPTION_KEY`**.

> **`OAUTH_PASSPHRASE` has to be set before the volume's first boot.** The passphrase is sealed
> into the private key when the key is generated, by `openssl_pkey_export()`; it is not a value the
> application checks at startup.

**So the entrypoint refuses to generate a pair while the variable is empty**, and logs the missing
variable by name. Not creating the wrong key is a stronger guarantee than documenting how to
replace it. It *skips* rather than failing the boot — halting would take the whole API down,
Radiant included, for a subsystem nothing consumes before phase 5 — so the API stays up, only
`/oauth2/*` cannot issue a token, and the first boot after the variable is set creates the pair
with nothing to repair.

Two cases the guard cannot cover, the second being the dangerous one:

| Situation | Symptom |
|---|---|
| a value was set, then changed | **Loud.** Every signature raises `LogicException: Unable to read key from file`, because `--skip-if-exists` keeps the key encrypted with the old value |
| an **unencrypted** pair already exists on the volume — born before this guard, or made by hand | **Silent.** OpenSSL ignores a passphrase handed to an unencrypted key, so everything works, the variable is set, and the private key stays in clear. The entrypoint now warns on every boot, since it cannot fix it alone: regenerating is a decision |

`head -2 config/oauth/private.pem` must contain `ENCRYPTED`. The volume table, the `--overwrite`
recovery command and the post-deploy checks are in [the deploy guide](../deploy/coolify.md).

### CORS and `TRUSTED_PROXIES`

Both are decided, and neither needed a change:

- **CORS stays scoped to `^/api/`.** `nelmio_cors` does not cover `/.well-known/*` or `/oauth2/*`,
  and nothing calling those is a browser subject to the origin policy: `/oauth2/authorize` is a
  top-level *navigation* (CORS does not apply), and `/oauth2/token` and the metadata documents are
  fetched by the MCP client — Claude Code, a Node process on the operator's machine, which sends
  no `Origin` and triggers no preflight. Registering a client that runs **in** a browser would be
  the trigger to revisit it, and that cannot happen by accident: such a client needs an `https://`
  web origin as its `redirect_uri`, not the loopback `http://localhost:3118/callback`.
- **`TRUSTED_PROXIES=private_ranges` is correct as it stands**, but it now carries more weight: the
  authorization server emits absolute URLs and redirects, so a wrong value sends the
  `/oauth2/authorize` → login → back bounce out on `http://` or on the internal host, and the flow
  never returns.

> **[`MCP_RESOURCE_URI`](#mcp_resource_uri--the-one-value-everything-hangs-off) is not affected by
> any of this.** It is explicit configuration, read from the environment and never derived from the
> request, so the `resource` member, the derived
> `issuer` and the `aud` claim are right even when the proxy settings are wrong. That is precisely
> why a bad proxy configuration shows up as a broken *redirect* rather than a wrong discovery
> document — starting from the metadata would waste the diagnosis.

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

**Phase 7 is done**, out of numeric order on purpose. The documents above publish a
`token_endpoint`, and until the volume existed production could not mint a token from it:
discovery worked, the flow behind it did not. It does now — see [Production](#production) for the
volume, the two Coolify environment variables, the entrypoint step, and the CORS and
`TRUSTED_PROXIES` decisions.
