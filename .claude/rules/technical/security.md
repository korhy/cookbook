---
description: Security — JWT authentication, the access_control map, CORS, secrets, untrusted input.
paths:
  - "**/config/packages/security.yaml"
  - "**/config/packages/nelmio_cors.yaml"
  - "**/config/packages/lexik_jwt_authentication.yaml"
  - "**/src/Controller/**/*.php"
  - "**/src/Mcp/**/*.php"
  - "**/.env*"
---

# Security

## Firewalls

Five firewalls, in `config/packages/security.yaml`, and **the declaration order matters**: the
first matching one runs, so `mcp_authoring` has to precede `api`.

- **`dev`** — profiler and assets, security off.
- **`oauth2_public`** (`^/(oauth2/token|\.well-known/)`) — `security: false`, so nothing can turn
  these into a redirect to the login form. A client whose token expired must be able to
  re-discover the server and refresh unauthenticated.
- **`mcp_authoring`** (`^/api/v1/mcp-authoring`) — **stateless**, `oauth2: true`, with
  `App\Security\McpBearerChallenge` as both `entry_point` and `access_denied_handler`. The MCP
  write path. See [mcp.md](mcp.md).
- **`api`** (`^/api`) — **stateless**, `json_login` on `/api/login_check`, then `jwt`. Tokens are
  issued and verified by **LexikJWTAuthenticationBundle** with an RSA keypair.
- **`admin`** — form login (`app_login`) with CSRF enabled, plus remember-me.

## The access_control map

Order matters — **only the first matching rule applies**:

```yaml
- { path: ^/api/login_check,      roles: PUBLIC_ACCESS }
- { path: ^/api/v1/docs,          roles: PUBLIC_ACCESS }
- { path: ^/api/v1/mcp-authoring, roles: MCP_WRITE }
- { path: ^/api/v1/mcp,           roles: PUBLIC_ACCESS }
- { path: ^/api,                  roles: ROLE_ADMIN }
- { path: ^/oauth2/authorize,     roles: IS_AUTHENTICATED_REMEMBERED }
- { path: ^/login,                roles: PUBLIC_ACCESS }
- { path: ^/admin,                roles: ROLE_ADMIN }
```

Three things follow that are easy to get wrong:

1. **The REST API is not public.** `^/api` requires `ROLE_ADMIN`, so every consumer — Radiant
   included — authenticates through `/api/login_check` and carries a Bearer token. Any new
   `/api/…` route inherits that unless a more specific rule is added *above* the `^/api` line.
2. **`/api/v1/mcp` and `/api/v1/docs` are genuinely public.** Anything reachable through them is
   world-readable. See [mcp.md](mcp.md).
3. **`^/api/v1/mcp-authoring` must stay ABOVE `^/api/v1/mcp`.** These are regexes, so the shorter
   pattern matches the longer path too: one line in the wrong order and the MCP write endpoint
   inherits `PUBLIC_ACCESS`, the firewall authenticates a bearer token that nothing then requires,
   and nothing looks wrong from the outside. `MCP_WRITE` is an attribute answered by
   `App\Security\McpScopeVoter`, not a role —
   `tests/Api/McpAuthoringEndpointTest.php` asserts the ordering held.

Never widen this map to make something "work". If a route needs to be public, say so explicitly and
place the rule deliberately.

## Authorization in code

- Use `#[IsGranted]`, voters, or `access_control` — **never** a raw role-string comparison
  (`in_array('ROLE_ADMIN', $user->getRoles())`) in business code.
- `ROLE_ADMIN` is the only meaningful role; `Admin` is the single account entity, resolved on
  **`username`**.
- **OAuth scopes are checked through a voter, not a role.**
  `league/oauth2-server-bundle` projects `mcp:write` onto the role `ROLE_OAUTH2_MCP:WRITE` by
  `strtoupper`-ing a prefix onto the scope name. That spelling is a vendor's `sprintf`, not ours,
  and it fails *open-ended* if their `role_prefix` ever changes — the rule stops matching and
  denies everything with nothing to say why. `App\Security\McpScopeVoter` names the scope once
  and reads it from the bundle's typed accessor. `#[IsGranted]` is not an option on an MCP tool
  either: it is resolved by controller argument metadata, so on a class the MCP bundle invokes it
  would never run.

## Keypairs — there are two, on purpose

- `config/jwt/private.pem` and `public.pem` (Lexik, `/api/login_check`) and
  `config/oauth/private.pem` and `public.pem` (the OAuth 2.1 authorization server) are both
  **git-ignored** and both in `.dockerignore`. `make jwt-keys` / `make oauth-keys` generate them,
  and the dev and prod entrypoints do it automatically on first boot with `--skip-if-exists`.
- They are **kept apart because their blast radii differ**: regenerating `config/jwt` logs out
  Radiant, regenerating `config/oauth` invalidates every MCP access and refresh token. Neither
  operation should force the other. Both are breaking operations in production.
- `JWT_PASSPHRASE` and `OAUTH_PASSPHRASE` live in `.env.local` in dev and in Coolify's runtime
  environment in production. **Never commit either**, never echo one into a log or a command you
  paste back. `OAUTH_ENCRYPTION_KEY` is the same kind of value.
- **`OAUTH_PASSPHRASE` is sealed into the private key at generation time.** Leaving it empty at the
  first boot writes the key in clear and raises no error at all, because OpenSSL ignores a
  passphrase given for an unencrypted key — so the variable being set proves nothing. Check
  `head -2 config/oauth/private.pem` for `ENCRYPTED`. See
  [docs/mcp/oauth-authorization-server.md](../../../docs/mcp/oauth-authorization-server.md).
- In production both directories are **persistent volumes**. Without them a redeploy issues a
  fresh pair and invalidates every token — silently, since the server keeps answering. See
  [docs/deploy/coolify.md](../../../docs/deploy/coolify.md).

## CORS

`nelmio/cors-bundle` guards `^/api/` only. `CORS_ALLOW_ORIGIN` is an **environment-provided regex**
(`^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$` in dev). Widening it — to `*` in particular — is a
security change: raise it, do not just do it.

Note that Radiant calls this API **server-side**, so it sends no `Origin` header and CORS does not
apply to it. CORS only matters for browser callers.

**`/.well-known/*` and `/oauth2/*` are outside that scope and stay there.** None of their callers
is a browser subject to the origin policy: `/oauth2/authorize` is reached by a top-level
*navigation*, and the token endpoint and the metadata documents are fetched by the MCP client, a
CLI process on the operator's machine. Registering a client that runs **in** a browser is what
would change the answer, and the fix would then be a new `paths` entry with its own
`allow_origin` — **never a wider `CORS_ALLOW_ORIGIN`**, which belongs to Radiant and `/api/`. The
reasoning is written out in
[docs/deploy/coolify.md](../../../docs/deploy/coolify.md).

## Secrets

- **No secret in a committed file.** `.env` holds non-secret defaults and is committed on purpose;
  real values go in `.env.local`, which is git-ignored.
- Never log credentials, a JWT, or the `JWT_PASSPHRASE`. Never paste them into an issue, a PR body
  or a commit message.
- **`.claude/settings.json` denies the file tools** (`Read`, `Edit`, `Write`) on `.env.local`,
  `.env.*.local`, `config/jwt/**` and `config/oauth/**`, so an assistant cannot pull a token or a
  private key into a transcript by accident — which is exactly how the old `MCP_WRITE_TOKEN` once
  ended up in one, before the OAuth server removed the shared secret entirely. It is a
  guard against accidents, **not a boundary**: the rules do not cover `Bash`, and
  `cat .env.local` or `docker exec cookbook_app env` walks straight past them. What actually
  protects the secret is the rule above — it has no reason to enter a conversation at all. One
  practical consequence: adding a variable to `.env.local` is now a manual edit, by hand.
- **Never put a database tool in the docroot** — not committed, not dropped in locally. The
  Adminer container on `http://localhost:8083` is how you get a database UI.

## Untrusted input

- Validate with constraints (`src/Validator/`, `Assert\*`), not with ad-hoc `if`s.
- Bind every query parameter (see [backend-php.md](backend-php.md)). The custom filters in
  `src/Filter/` build `LIKE` clauses from user input — that is exactly the place where a bound
  parameter is the difference between a filter and an injection.
- MCP tool arguments arrive unauthenticated. Same rule, higher stakes.
