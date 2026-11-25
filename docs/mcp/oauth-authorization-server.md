# The OAuth 2.1 authorization server

Cookbook hosts its own authorization server, built on **`league/oauth2-server-bundle` 2.0**, to
protect the MCP write path.

**It is now the only way to write.** `MCP_WRITE_TOKEN` is gone from the repository, and so is the
`string $token` parameter the two write tools used to take. That parameter was the actual bug: the
MCP bundle derives a tool's advertised JSON Schema from its PHP signature, so declaring the
credential as an *argument* announced it to the client as a required field — and a human ended up
pasting the secret in clear into a conversation to make the tool work. The credential now travels
in an `Authorization` header, which a client can hold without a person ever reading it.

The write tools live on a **second MCP server** at `/api/v1/mcp-authoring`, behind a firewall of
their own. The public read server at `/api/v1/mcp` is untouched: same path, same four tools, still
anonymous.

Related: [the client registration decision](../decisions/mcp-oauth-client-registration.md) ·
[where `aud` can be stamped](../decisions/mcp-oauth-audience-claim.md)

---

## What exists

| Endpoint | Security | Notes |
|---|---|---|
| `GET\|POST /oauth2/authorize` | `IS_AUTHENTICATED_REMEMBERED` | Bounces to the admin form login and back, then shows [the consent screen](#the-consent-screen) |
| `POST /oauth2/token` | none (`security: false`) | Must stay reachable with no session, or an expired client can never refresh |
| `POST /oauth2/device-code` | none | Declared by the bundle, inert: the device code grant is off, so league answers `unsupported_grant_type` |
| `GET /.well-known/oauth-authorization-server` | none (`security: false`) | RFC 8414 metadata — [below](#discovery) |
| `GET /.well-known/oauth-protected-resource[/api/v1/mcp-authoring]` | none (`security: false`) | RFC 9728 metadata — [below](#discovery) |
| `GET\|POST\|DELETE /api/v1/mcp-authoring` | **`mcp_authoring` firewall**, scope `mcp:write` | The MCP write tools — [below](#the-protected-endpoint) |
| `GET\|POST\|DELETE /api/v1/mcp` | `PUBLIC_ACCESS` | The public read server, unchanged |

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
3. the RFC 8707 audience validation in `App\Security\McpTokenAudienceVerifier`.

Hence the rules, enforced in `App\OAuth\McpResourceUri`'s constructor rather than left to a
comment: an absolute `http(s)` URL, no query, no fragment, no credentials, **no trailing slash**. A
malformed value throws on the first request that needs it, because the alternative is a server that
rejects every token with nothing in the logs that says why.

**It is the URL of `/api/v1/mcp-authoring`, not of the public read endpoint.** The resource
identifier has to be the thing that answers `401`, so that every link in the chain names one
string: the challenge points at the metadata document, whose `resource` member is this value, which
the client sends back as its `resource` parameter, which lands in `aud`, which is compared here.
Pointing it at `/api/v1/mcp` would be self-consistent and still wrong — a client would be told to
get a token for a URL it never calls. The path appears in four places that cannot check each other
(`http.path` in `mcp.yaml`, the firewall `pattern`, the `access_control` rule, and this variable),
so `tests/OAuth/McpResourceUriPathTest.php` asserts it against the route the bundle registers.

The **issuer is derived from it**: the same URL with its path dropped. The authorization server and
the resource server are the same application, and RFC 8414 locates an issuer's metadata at
`<issuer>/.well-known/oauth-authorization-server` — a document served from the root therefore
describes an issuer with no path. Deriving it removes the one way these two documents could
contradict each other.

### Why the bare `/.well-known/oauth-protected-resource` is served as well

RFC 9728 §3.1 inserts the resource's path after the well-known segment when the resource
identifier has one, which gives the canonical
`/.well-known/oauth-protected-resource/api/v1/mcp-authoring`.
The bare form is served too because it is what a client that treats the resource identifier as an
origin will try, and this application hosts exactly one protected resource — there is no second
document the bare path could be confused with, and `resource` names the MCP endpoint either way.

Any **other** path (`/.well-known/oauth-protected-resource/api/v1/recipes`, say) is a 404. It asks
about a resource we do not protect, and answering would tell a client this document describes it.

### The `aud` claim

`App\OAuth\AudienceStampingAccessTokenRepository` decorates
`league.oauth2_server.repository.access_token` so that every issued access token names the
authoring endpoint in its audience. league ships no RFC 8707 support and hard-codes the *client*
identifier as `aud`, so without this there would be nothing to validate. The full reasoning,
including the three hooks that do not work, is in
[the audience decision](../decisions/mcp-oauth-audience-claim.md).

The claim is an **array of two, `["<client id>", "<resource uri>"]`, and the order is
load-bearing.** `BearerTokenValidator` recovers the client identifier from `$claims->get('aud')[0]`
positionally (BearerTokenValidator.php:137), with no check of what it finds there. Seeding the
resource URI first — which is what phase 3 did — therefore made every authenticated request report
the resource URI as its OAuth client. Harmless while nothing authenticated; wrong the moment this
endpoint went live, since the audit trail and the write path's rate-limit key are both built on that
identity. `App\OAuth\AudienceAwareAccessToken` now seeds both entries in that order, and league's
own `permittedFor()` call appends nothing because lcobucci merges with dedup. RFC 7519 §4.1.3
allows the array form, and a resource server validates by membership.

---

## The protected endpoint

`/api/v1/mcp-authoring` is a **second MCP server**, declared beside the public one in
`config/packages/mcp.yaml` with `App\Mcp\Tool\Write\` as its `registry.tools` and
**`stdio: false`** — a piped caller has no Authorization header, so there would be no identity to
authorize, meter or record.

It exists as a separate endpoint for one concrete reason: **an MCP tool error is a JSON-RPC object
inside a `200`.** A server with mixed tools can only ever refuse a caller in the body, and `401`
with a challenge, `403 insufficient_scope` and RFC 9728 discovery are all transport answers.

Four layers stand in front of a write, each answering a different question:

| Layer | Question | Refusal |
|---|---|---|
| `mcp_authoring` firewall (`oauth2: true`) | is this a token this server signed, unexpired and unrevoked? | `401` + challenge |
| `App\Security\McpTokenAudienceVerifier` | was it issued for **this** resource? (RFC 8707, exact equality) | `401 invalid_token` |
| `App\Security\McpScopeVoter` via `access_control` | does the grant carry `mcp:write`? | `403 insufficient_scope` |
| `App\Service\Mcp\McpWriteGuard` | has this identity written too much, and is it on the record? | JSON-RPC error in a `200` |

### The scope check is a voter, not a role

league projects a token's scopes onto roles by string concatenation, so `mcp:write` becomes the role
**`ROLE_OAUTH2_MCP:WRITE`** — colon included, `strtoupper`'d. That identifier is an artefact of a
vendor's `sprintf`, and writing it into `security.yaml` would hard-code a spelling we do not own,
duplicate the scope name in a silently-transformed second form, and fail *open-ended* if a future
`role_prefix` setting changed it: the rule would stop matching and deny everything, with nothing to
say why. `App\Security\McpScopeVoter` names the scope once and reads it from the bundle's typed
accessor instead, answering the `MCP_WRITE` attribute.

`#[IsGranted]` was the other candidate and does not work here: an MCP tool is not a controller, so
the attribute — resolved by Symfony's controller argument metadata — would be decoration that never
runs. The quietest possible way to ship an unprotected write tool.

### The access_control ordering trap

`access_control` paths are **regexes**, so `^/api/v1/mcp` matches `/api/v1/mcp-authoring` too. The
authoring rule is therefore placed **above** the public `PUBLIC_ACCESS` one; below it, the write
endpoint would inherit `PUBLIC_ACCESS`, the firewall would authenticate a bearer token that nothing
then required, and nothing would look wrong from the outside. The firewall itself is declared above
`api` for the same class of reason: firewalls match in declaration order, and `^/api` would
otherwise hand the endpoint to the Lexik JWT authenticator.

`McpAuthoringEndpointTest` asserts both orderings held.

### Rate limiting and the audit trail outlived the secret

`McpWriteGuard` no longer decides *whether* a caller may write. It kept the two jobs the firewall
does not do:

- **Rate limiting**, keyed on `<oauth client>:<user>` — not on the access token, which lives one
  hour and would hand a caller a fresh budget with every refresh. A valid grant is not a licence to
  create 10,000 drafts, and OAuth has nothing to say about volume.
- **The audit trail** on the `mcp_audit` channel: the tool, the client, the user it acted for, the
  caller's IP, and an 8-character SHA-256 fingerprint of the **access token id** — never a
  credential. Authorization proves a caller may write; only the record says what they did.

The third limiter, `mcp_write_auth`, was there to blunt guesses at the shared secret and lost that
job with it — an RSA-signed JWT is not guessable. It is kept with a narrower purpose: refusing a bad
token costs a signature verification plus a revocation lookup in Postgres every time, so an
anonymous caller can make this server work by presenting garbage.
`App\EventListener\McpAuthenticationFailureListener` consumes it per client IP and answers `429`
once the window is empty.

### The draft quarantine is unchanged, and that is the point

Everything `recipe_create` writes is still a `draft`, invisible to `/api/v1` and to the read tools
until a human publishes it in EasyAdmin. That defence is independent of authentication and keeps its
full meaning now that OAuth is in front of it: **an authorized caller is not a reviewed one.**

### The `WWW-Authenticate` challenge

`App\Security\McpBearerChallenge` answers `401` with
`WWW-Authenticate: Bearer …, resource_metadata="<the RFC 9728 URL>"`, and `403` with
`error="insufficient_scope"`. That `resource_metadata` pointer is what gets a client from one
failed request to the authorization server, with no URL configured by hand.

It is wired as the `mcp_authoring` firewall's **`entry_point` and its `access_denied_handler`** —
the two halves produce the `401` and the `403` respectively.

**That covers requests with no token, and not much else**, which is why
`App\EventListener\McpAuthenticationFailureListener` exists. An entry point only runs when no
authenticator claimed the request. League's `OAuth2Authenticator` claims anything with a `Bearer `
header and answers its own failures, returning `new Response($message, 401, ['WWW-Authenticate' =>
'Bearer'])` — a bare challenge with no pointer. So the client whose hour-old token has just
expired, the one that needs the pointer most, is exactly the one league would not give it to. The
listener runs on `LoginFailureEvent`, whose response wins, and replaces it with the real challenge.

---

## The consent screen

`/oauth2/authorize` shows the Admin what is being asked and waits for an answer. Three classes and
one template:

| File | Role |
|---|---|
| `App\EventListener\OAuthConsentListener` | Listens on `OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE` and turns a decision into an HTTP outcome |
| `App\OAuth\ConsentDecider` | Reads the decision off the request, and **validates the CSRF token** |
| `App\OAuth\ConsentDecision` | The four states: `Pending`, `Approved`, `Denied`, `InvalidCsrfToken` |
| `templates/oauth2/consent.html.twig` | The screen itself, through the `oauth2_consent.*` catalogue keys |

The screen shows the client's **name** (not just `cookbook-mcp-authoring`, which is not something a
human recognises), its identifier underneath so the claim is verifiable, the requested scope both
as a translated sentence and as the raw `mcp:write`, and two buttons: authorize and refuse.

### The default was not "approve" — it was "deny"

Worth stating plainly, because the phase plan assumed the opposite. `league/oauth2-server-bundle`
ships **no listener** on `AUTHORIZATION_REQUEST_RESOLVE` (its only `EventListener/` entry is
`AddClientDefaultScopesListener`, on `PRE_SAVE_CLIENT`), and
`AuthorizationRequestResolveEvent::$authorizationResolution` initialises to
`AUTHORIZATION_DENIED`. `AuthCodeGrant::completeAuthorizationRequest` then throws `accessDenied`.

So before this phase, a *valid* request from a logged-in Admin was redirected straight back to the
client with `error=access_denied`. **The endpoint could never issue a code at all.** This phase is
therefore not only what makes the gate visible; it is what makes the authorization endpoint work.

That also means the fail-safe direction is the right one by construction: if the listener returns
without resolving anything — the `null === $request` branch — the request is denied, not granted.

### Why the form posts back to the full request URI

`AuthCodeGrant::validateAuthorizationRequest` reads **every** parameter through
`getQueryStringParameter` — `client_id`, `redirect_uri`, `scope`, `state`, `code_challenge`,
`code_challenge_method`. Never the body. The controller re-validates from scratch on the POST, so
the form's `action` is `app.request.requestUri`: the original query string, verbatim. The body
carries nothing but the CSRF token and which button was pressed.

Moving a parameter into a hidden field instead would fail with `invalid_request` and no hint
pointing at the cause.

### `setResponse()` versus `resolveAuthorization()`

These are the two ways out of the event, and they are not interchangeable:

- **`setResponse($response)`** — `AuthorizationController` returns it *verbatim* and never reaches
  the grant. Nothing is issued, nothing is redirected, and the client learns nothing. This is the
  consent screen, and the rejection of a bad CSRF token.
- **`resolveAuthorization(bool)`** — the grant runs. `true` issues the code and redirects;
  `false` makes league build the RFC 6749 §4.1.2.1 redirect, `error=access_denied` with the
  client's `state` preserved.

A refusal therefore lands on the **client's** `redirect_uri`, not on an error page of ours. That is
the spec's requirement and also the only behaviour a client can recover from.

### CSRF — the security point of this phase

**Any** POST to `/oauth2/authorize` must carry a valid token, whatever it claims to submit. The
Admin's approval is presented throughout this project as *the* gate on the MCP write path, so a
page on another origin able to POST an approval would remove that gate silently, while every
document still said it was there.

A rejected submission is answered with the screen again at **`400`** — never with a redirect. The
client must learn nothing from a forged POST, and the Admin gets a form with a fresh token.
`InvalidCsrfToken` is deliberately a state of its own rather than an alias for `Denied`: a forged
request must not be able to cause a redirect to a client either.

> **The token id `oauth2_consent` is deliberately *absent* from
> `framework.csrf_protection.stateless_token_ids`** in `config/packages/csrf.yaml`.
> `SameOriginCsrfTokenManager` delegates any id not listed there to the session-backed manager,
> which is what we want: the stateless double-submit check needs a JavaScript snippet to plant the
> cookie, and this screen has none — while a session is guaranteed, since the caller has just
> authenticated against the stateful `admin` firewall to get here. Adding the id to that list would
> break the form, and the failure would look like a CSRF bug rather than a configuration one.

### Translations

The keys are `oauth2_consent.*` in **both** `translations/messages.fr.yaml` and
`messages.en.yaml`. The scope sentence is keyed by the scope identifier with `:` swapped for `_`
(`oauth2_consent.scope.mcp_write`), because a colon cannot nest as a YAML key — and the raw
identifier is printed beside the sentence so a scope with no key yet is still readable instead of
rendering as a bare key.

`App\EventListener\LocaleListener` picks the locale from `Accept-Language`. A request with **no**
header renders in `en` (`framework.default_locale`), which is why
`tests/Controller/OAuth2ConsentTest.php` drives the header explicitly rather than relying on the
default — otherwise it would only ever have covered one catalogue.

### What the tests guard

`tests/Controller/OAuth2ConsentTest.php`, six tests:

1. a valid request **shows the screen** rather than resolving itself;
2. approving redirects to the client with a `code` and the original `state`;
3. refusing redirects with `error=access_denied`, the `state` preserved and no `code`;
4. a submission with an invalid CSRF token is rejected — `400`, **no** `Location` header at all;
5. two locales, asserting that no key renders raw in either catalogue.

The test registers its own public client through `ClientManagerInterface` (the test environment
persists `oauth2_*` in memory) and calls `disableReboot()` — `KernelBrowser` reboots the kernel
between requests by default, which would throw away both the registered client and the session
holding the CSRF token between the GET and the POST.

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
Radiant included, and the public read API does not depend on OAuth — so the API stays up, only
`/oauth2/*` cannot issue a token and `/api/v1/mcp-authoring` cannot be reached, and the first boot
after the variable is set creates the pair with nothing to repair.

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

### Wiring a client

**Two separate MCP servers, and only one of them needs OAuth.** The read tools stay anonymous, so
add them with no credential at all:

```bash
claude mcp add --transport http cookbook https://api.clementboudinel.fr/api/v1/mcp
```

The authoring server is a second entry, pointed at the protected path:

```bash
claude mcp add --transport http cookbook-authoring https://api.clementboudinel.fr/api/v1/mcp-authoring --client-id cookbook-mcp-authoring --callback-port 3118
```

No `--client-secret`: the client is public. The first call to an authoring tool opens the browser at
`/oauth2/authorize`, the admin logs in with the existing form login, approves on the consent screen,
and the token comes back to `http://localhost:3118/callback`. **Nothing is typed into the
conversation at any point** — which is the whole objective.

Adding the authoring server alone also works: a client that discovers it gets the `401`, follows the
`resource_metadata` pointer and finds the authorization server without any of this being configured
by hand. The flags above only pin what league matches by exact string.

#### Migrating from `MCP_WRITE_TOKEN`

A client configured against the old shared secret **stops working** and has to be reconfigured:

1. Remove the old entry (`claude mcp remove cookbook`) and re-add both servers as above.
2. Register the client once on the server, if it is not registered yet — see
   [the pre-registered client](#the-pre-registered-client).
3. Delete `MCP_WRITE_TOKEN` from `.env.local` and from Coolify's environment. Nothing reads it any
   more, and leaving a stale secret lying about is the thing this work set out to stop.

Calls that used to pass a `token` argument now fail with an unknown-parameter error from the SDK:
the parameter is gone from the advertised schema, deliberately.

---

## What is not here yet

| Phase | Missing piece |
|---|---|
| 6 | End-to-end tests of the whole browser flow — a real client driving `/oauth2/authorize`, the consent screen and `/oauth2/token`, then calling an authoring tool with the token that comes back |

Everything the write path needs at runtime is in place and covered: `tests/Api/McpAuthoringEndpointTest.php`
proves the `401` with its `resource_metadata` pointer, the `403 insufficient_scope`, the refusal of a
signed token minted for another audience, the draft quarantine and the audit trail, with tokens
minted through the real issuing path. What phase 6 adds is the half no test here exercises: the
*browser* leg, from authorization request to code to token.

**`POST /oauth2/token` no longer answers a bare `500` when the keypair cannot be read.**
`League\OAuth2\Server\CryptKey` reads and decrypts the key in its *constructor*, so a missing file
or a wrong `OAUTH_PASSPHRASE` fails while the service is being instantiated, before any controller
code runs. `App\EventListener\OAuthKeyFailureListener` now turns that into league's own
`{"error": "server_error"}` envelope — the shape a client already parses — and logs the real message,
which carries the key path, to the error log instead of returning it. It recognises the failure by
the file it was thrown from, resolved by reflection on `CryptKey`, rather than by matching a message
whose wording is a vendor's to change.

**Phase 7 is done**, out of numeric order on purpose. The documents above publish a
`token_endpoint`, and until the volume existed production could not mint a token from it:
discovery worked, the flow behind it did not. It does now — see [Production](#production) for the
volume, the two Coolify environment variables, the entrypoint step, and the CORS and
`TRUSTED_PROXIES` decisions.
