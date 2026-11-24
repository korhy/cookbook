# The OAuth 2.1 authorization server

Cookbook hosts its own authorization server, built on **`league/oauth2-server-bundle` 2.0**, to
protect the MCP write path. It replaces the shared secret that `recipe_create` and
`recipe_import_from_url` take as a tool argument today — a token passed as an argument ends up
pasted in clear in the conversation, which is the whole reason for this work.

**This document covers phase 2 only: the server is installed and wired, and nothing uses it yet.**
The MCP write tools still go through `MCP_WRITE_TOKEN` and `App\Service\Mcp\McpWriteGuard`, and they
keep doing so until phase 5.

Related: [the client registration decision](../decisions/mcp-oauth-client-registration.md) ·
[where `aud` can be stamped](../decisions/mcp-oauth-audience-claim.md)

---

## What exists after phase 2

| Endpoint | Security | Notes |
|---|---|---|
| `GET\|POST /oauth2/authorize` | `IS_AUTHENTICATED_REMEMBERED` | Bounces to the admin form login and back. The consent screen is phase 4 |
| `POST /oauth2/token` | none (`security: false`) | Must stay reachable with no session, or an expired client can never refresh |
| `POST /oauth2/device-code` | none | Declared by the bundle, inert: the device code grant is off, so league answers `unsupported_grant_type` |

Grants enabled: **`authorization_code` + `refresh_token`, and nothing else.** Client credentials
defaults to *on* in the bundle and is explicitly turned off — it would issue an `mcp:write` token
with no human in the loop. Password, implicit and device code are off too.

One scope exists: **`mcp:write`**. PKCE is mandatory
(`require_code_challenge_for_public_clients: true`).

Four tables, created by `Version20261124130226`: `oauth2_client`, `oauth2_authorization_code`,
`oauth2_access_token`, `oauth2_refresh_token`.

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
| 3 | RFC 9728 / RFC 8414 metadata at `/.well-known/*`, the `WWW-Authenticate` challenge, and the `aud` decorator |
| 4 | The consent page on `/oauth2/authorize` |
| 5 | The `mcp_authoring` firewall, `McpScopeVoter`, and dropping `string $token` from the two write tools |
| 7 | The production volume for `config/oauth/` and the Coolify environment variables |
