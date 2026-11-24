# MCP OAuth — how a client obtains its `client_id`

**Date:** 2026-11-24 · **Status:** accepted · **Scope:** the OAuth 2.1 authorization server that will
protect the MCP write path

## The question

The MCP authorization specification (revision **2026-07-28**) lets a client obtain a `client_id`
three ways, and states a priority order for clients that support all of them:

> 1. pre-registered · 2. Client ID Metadata Documents · 3. Dynamic Client Registration · 4. prompt the user

It also deprecates DCR outright: *"Dynamic Client Registration is deprecated. New implementations
should use Client ID Metadata Documents instead."*

Which mechanism we implement decides whether the authorization server needs a public
`POST /register` endpoint, an outbound metadata fetch, or neither. The spec alone cannot answer it —
what matters is what the clients that will actually connect are able to do.

## Evidence

Three independent checks, all reproducible:

1. **The installed client advertises every discovery field we would publish.** Scanning the
   Claude Code binary (`~/.local/share/claude/versions/2.1.331`) for OAuth literals returns
   `client_id_metadata_document_supported`, `registration_endpoint`, `/register`,
   `oauth-authorization-server`, `oauth-protected-resource`, `resource_metadata`,
   `code_challenge_methods_supported`, `insufficient_scope` and `openid-configuration`. The CIMD and
   RFC 9207 flags appear inside the authorization-server metadata parsers, so the client reads them;
   their presence proves parsing, not a wired flow.

2. **The official SDK's documented client path is DCR.** In
   `modelcontextprotocol/typescript-sdk`, `docs/clients/oauth.md` describes `OAuthClientProvider`
   storing credentials keyed by `ctx.issuer` ("Key DCR-obtained credentials by issuer", SEP-2352) and
   says the SDK "registers (or looks up) your OAuth client". CIMD is not mentioned on that page.
   Static credentials are possible, but only for someone writing a client.

3. **Pre-registration is configurable by the operator — this is the decisive find.**
   `claude mcp add --help` exposes:

   | Option | Meaning |
   |---|---|
   | `--client-id <clientId>` | the OAuth client ID to use for an HTTP/SSE server |
   | `--client-secret` | prompt for a secret, or read `MCP_CLIENT_SECRET` |
   | `--callback-port <port>` | fixed callback port, "for servers requiring pre-registered redirect URIs" |

   So the first-priority mechanism in the spec's own list is available without us building anything.

## Decision

**Pre-registration is the supported mechanism. We implement neither DCR nor CIMD for now.**

- One OAuth client, created with `league:oauth2-server:create-client`, **public** (no secret) and
  therefore PKCE-mandatory: a CLI on an operator's machine cannot keep a secret, so a confidential
  client would be theatre.
- Its redirect URI is pinned to the loopback callback that `--callback-port` fixes. The exact path is
  read off the first `/oauth2/authorize` request's `redirect_uri` parameter and registered verbatim —
  league validates redirect URIs by exact match.
- `client_id_metadata_document_supported` is **not** advertised while CIMD is unimplemented.
  Advertising a capability we do not honour is worse than omitting it.

## Consequences

- **No `POST /oauth2/register`.** No open registration endpoint, so no anonymous way to create
  rows in `oauth2_client`, and nothing to rate-limit there.
- **No outbound fetch in the authorization path.** The CIMD resolver, its domain allowlist and its
  reuse of `App\Service\Http\SsrfGuardedFetcher` are all dropped, and with them the hashing of a
  metadata URL into league's 32-character `oauth2_client.identifier`.
- **The consent screen stays the real gate.** Whatever client asks, a token is only ever issued
  after the `Admin` account logs in through the existing form login and approves the request.
- The planned phase 4 shrinks to a console command invocation plus the consent page.