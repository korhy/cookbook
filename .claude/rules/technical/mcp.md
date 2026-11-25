---
description: MCP server conventions — read tools and the token-gated write tools under src/Mcp/Tool, the write guard, SSRF-guarded fetching, argument handling, allowed_hosts, testing.
paths:
  - "**/src/Mcp/**/*.php"
  - "**/src/Service/Mcp/**/*.php"
  - "**/src/Service/Http/**/*.php"
  - "**/src/DTO/Mcp/**/*.php"
  - "**/src/Exception/Mcp/**/*.php"
  - "**/config/packages/mcp.yaml"
  - "**/config/packages/rate_limiter.yaml"
  - "**/config/routes/mcp.yaml"
  - "**/tests/Mcp/**/*.php"
  - "**/src/Security/Mcp*.php"
  - "**/src/EventListener/Mcp*.php"
  - "**/tests/Service/Mcp/**/*.php"
  - "**/tests/Service/Http/**/*.php"
---

# MCP server

`symfony/mcp-bundle` publishes **two** servers, and which one a tool lands on is the security
boundary of this whole area:

| Server | Endpoint | Transports | Tools | Security |
|---|---|---|---|---|
| `cookbook` | `/api/v1/mcp` | stdio + HTTP | `App\Mcp\Tool\Read\` | `PUBLIC_ACCESS` — anonymous |
| `authoring` | `/api/v1/mcp-authoring` | HTTP only | `App\Mcp\Tool\Write\` | OAuth 2.1, `mcp:write` scope |

**The split exists because an MCP tool error is a JSON-RPC object inside a `200`.** A server with
mixed tools can only refuse a caller in the body; `401` with a `WWW-Authenticate` challenge and
`403 insufficient_scope` are *transport* answers, and RFC 9728 discovery is built on them. So the
write path needs an endpoint of its own, with a firewall of its own.

Which server a tool belongs to is decided by the `registry:` namespace prefix in
`config/packages/mcp.yaml`, which is why `src/Mcp/Tool/` is split into `Read/` and `Write/`: **the
directory a tool sits in is its server.** The two cannot drift apart, and a new tool cannot reach
the public endpoint by accident. The authoring server declares `stdio: false` — a piped caller
carries no Authorization header, so there would be no identity to authorize, meter or record.

## The contract

### The public read server

**Public and unauthenticated.** Two rules hold for *every* tool, and neither is negotiable:

1. **No tool may expose more than the REST API already does.** If a field is not in a `:read`
   serialization group, it does not belong in an MCP response either. Admin credentials, internal
   IDs of unexposed entities, file paths: never.
2. **Every argument is untrusted.** It arrives from a caller who may not have authenticated at all.
   It must reach the database only through a bound parameter, via a repository method.

Beyond that the tools split into two families, and which one you are writing decides everything
else.

### Read tools — the default

`recipe_search`, `recipe_get`, `category_list`, `ingredient_search`. Unauthenticated, and **they
must not write**: no persist, no flush, no delete, no command dispatch. Results are bounded
(`recipe_search` caps at 5, `ingredient_search` at 10, `category_list` at 50) — a tool that can
return the whole 13k-row table is a defect. They see **published recipes only**; `RecipeRepository::searchByKeywords()` and
`findOneBySlug()` filter on status for that reason.

### Write tools — the sanctioned exception, not a precedent

`recipe_create` and `recipe_import_from_url`, on the `authoring` server. These exist because
writing recipes from an MCP client was a deliberate product decision, taken with the security
design below. **Adding a third is a decision for the user, not a step in a feature.** Any new one
must go in `App\Mcp\Tool\Write\` and reuse every layer:

0. **No credential in the signature. Ever.** The bundle derives a tool's advertised JSON Schema
   from its `__invoke()` parameters, so a `string $token` parameter announces the credential to the
   client as a required field — which is how `MCP_WRITE_TOKEN` ended up pasted in clear into a
   conversation, and the reason the OAuth server exists. Authorization belongs to the firewall, in
   a header; `McpAuthoringEndpointTest::testNoWriteToolAdvertisesACredentialParameter` fails if a
   credential parameter ever comes back.
1. **`McpWriteGuard` first, before anything else happens.** Call `assertMayWrite()` (or
   `assertMayFetch()` for outbound requests) as the first statement. It no longer decides *whether*
   a caller may write — the firewall does — but it still meters and records, and it throws rather
   than returning a status, precisely so that forgetting to call it is not something you can do by
   accident. For `recipe_import_from_url` the ordering is the thing that keeps the importer from
   being an open proxy: the guard must refuse before the fetcher reaches the network.
2. **Drafts, never published rows.** `recipe_create` sets `RecipeStatus::Draft`. A draft is filtered
   out of `/api/v1` by `App\Doctrine\Extension\PublishedRecipeExtension` and out of the read tools
   by the repository. Publication happens in EasyAdmin, by a human.
3. **Validate twice.** The advertised JSON Schema is enforced by the SDK before your code runs, but
   a caller controls what it sends — so the DTOs in `src/DTO/Mcp/` re-check everything, and the
   entity itself goes through `ValidatorInterface` (which is what finally applies `BanWord` on this
   path).
4. **Bound everything.** 50 ingredients, 50 instructions, **5 newly-created ingredients per call**,
   2000 characters per step. An unbounded field on a public write path is a storage-exhaustion
   vector.
5. **One transaction.** `RecipeDraftFactory` writes the whole graph inside
   `wrapInTransaction()`, so a rejected draft leaves no orphaned ingredients behind.
6. **Audit, without leaking.** The guard logs to the `mcp_audit` channel: the tool, the OAuth
   client, the user it acted for, the caller's IP, and an 8-character SHA-256 fingerprint of the
   **access token id** — never a credential. Authorization proves a caller may write; only the
   record says what they did.

`UrlFetchRejectedException` stays vague about *why* a host was refused: naming the resolved address
would make the importer an internal network scanner. The guard's own refusals no longer need to be
uniform — a caller reaching it is authenticated, so "rate limit reached" tells them nothing they
should not know, and a client that cannot tell throttled from refused retries forever.

### The authorization layers

Four distinct checks stand in front of a write, and each one answers a different question:

| Layer | Question | Refusal |
|---|---|---|
| `mcp_authoring` firewall (`oauth2: true`) | is this a token this server signed, unexpired and unrevoked? | `401` + challenge |
| `App\Security\McpTokenAudienceVerifier` | was it issued for **this** resource? (RFC 8707, exact equality) | `401 invalid_token` |
| `App\Security\McpScopeVoter` via `access_control` | does the grant carry `mcp:write`? | `403 insufficient_scope` |
| `App\Service\Mcp\McpWriteGuard` | has this identity written too much, and is it on the record? | JSON-RPC error in a `200` |

`App\Security\McpBearerChallenge` is the firewall's `entry_point` **and** its
`access_denied_handler`, which is what produces the first and third rows. The second row throws
from `CheckPassportEvent`, and `App\EventListener\McpAuthenticationFailureListener` is what turns
every authentication failure into the challenge — league's authenticator answers its own failures
with a bare `WWW-Authenticate: Bearer` and no `resource_metadata`, so without that listener the
client whose token just expired is the one denied the discovery pointer.

Full procedure, including registering a client: [docs/mcp/oauth-authorization-server.md](../../../docs/mcp/oauth-authorization-server.md).

### The safe default

There is **no shared write secret to configure**, and nothing to enable. A deploy with no
registered OAuth client answers `401` to every write attempt, which is the intended default state.
With **`MCP_IMPORT_ALLOWED_HOSTS` empty** — which it is in the committed `.env` — no URL can be
fetched at all either.

The write tools are not listed in the public server's `tools/list` any more; they are not on it.
That is the one thing the per-server `registry:` buys that a runtime check never could: the
`McpTool` attribute is autoconfigured at container-compile time, so a tool could never be
unregistered *because* a credential was missing.

## Writing a tool

`RecipeSearchTool`, `RecipeGetTool` and `CategoryListTool` (in `App\Mcp\Tool\Read\`) are the
templates for a read tool; `RecipeCreateTool` (in `App\Mcp\Tool\Write\`) is the template for a
write one. The read shape:

```php
#[McpTool(
    name: 'recipe_search',
    description: 'Search for recipes by keyword… Returns up to 5 matches.',
)]
final class RecipeSearchTool
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
        private readonly RecipePresenter $presenter,
    ) {}

    /** @return array{recipes: array<int, array{id: ?int, title: ?string, …}>} */
    public function __invoke(string $keywords): array { … }
}
```

Tools are `final` and carry `declare(strict_types=1)`. **Map the entity through
`App\Service\Mcp\RecipePresenter` rather than by hand** — it is the single place where "a read
tool may not expose a field the REST API does not" stays checkable.

- **`name`** is snake_case and stable. Renaming one breaks every configured client — treat it like
  an API rename.
- **`description`** is the only thing a model sees when deciding whether to call the tool. Say what
  it returns *and its limits* ("Returns up to 5 matches"), not just what it searches.
- **`__invoke()` typed arguments are the input schema.** The bundle derives it from the signature,
  so type them precisely; no untyped or `mixed` parameters.
- **For a nested argument, use `#[Schema]` with its individual properties** (`type:`, `items:`,
  `minItems:`, `maxItems:`), as `RecipeCreateTool` does. **Not `#[Schema(definition: [...])]`** —
  `Schema::toArray()` returns that key verbatim, `definition` is not a JSON Schema keyword, and the
  result is a schema clients ignore and the SDK does not validate. It fails silently.
- **A PHP attribute cannot call `Enum::cases()`.** That is why `IngredientUnit::VALUES` exists as a
  literal list, with `IngredientUnitTest` asserting it has not drifted from the cases.
- **Return a shaped array with an explicit PHPDoc generic.** PHPStan level 5 runs over `src/`, and
  the array shape is what keeps the response honest.
- **Bound results.** `searchByKeywords()` caps at 5 rows via `setMaxResults(5)`. A tool must never
  be able to return the whole table.
- **Query in the repository, never in the tool.** The tool maps entities to a plain array; that is
  all it does.

## Configuration

`config/packages/mcp.yaml` declares both servers under `mcp.servers` — the shape since bundle 0.13
replaced the single implicit server — each with its own `registry.tools` namespace prefix. A pattern
that matches nothing fails the build, so renaming either namespace cannot silently empty a server.

**`http.path` is explicit on both and must stay so**: the default is `/mcp/<server name>`, which the
`/api/v1` prefix in `config/routes/mcp.yaml` would turn into `/api/v1/mcp/cookbook`.

**The authoring path is not under `/mcp`, deliberately.** `access_control` paths are regexes, so
anything starting with `/api/v1/mcp` is also matched by the `^/api/v1/mcp` `PUBLIC_ACCESS` rule.
`security.yaml` places the `^/api/v1/mcp-authoring` rule **above** it, and the distinct name is the
second line of defence; `McpAuthoringEndpointTest` asserts the ordering held.

`MCP_RESOURCE_URI` is the absolute URL of **the authoring path**, not the public one — the resource
identifier has to be the thing that answers `401`, so that the 401, the metadata document, the
client's `resource` parameter, the `aud` claim and the audience check all name one string.
`McpResourceUriPathTest` asserts its path against the route the bundle really registers, because
nothing else would: a mismatch refuses every request and logs nothing useful.

`allowed_hosts` is restricted per environment:

| Env | Hosts |
|---|---|
| prod | `api.clementboudinel.fr` |
| dev / test | `localhost`, `127.0.0.1`, `cookbook_app` |

A tool that "does not answer" from inside a container is almost always a `Host` header missing from
that list — check it before debugging the tool itself.

Sessions are file-backed under `%kernel.cache_dir%/mcp-sessions/<server>` with a 3600s TTL — the
bundle scopes the store per server, and two servers may not share one, which is a compile-time
error. `make cc` wipes them; that is expected.

## Configuration for the write path

| Variable | Default | Effect when unset |
|---|---|---|
| `MCP_IMPORT_ALLOWED_HOSTS` | empty | `recipe_import_from_url` fetches nothing |
| `MCP_RESOURCE_URI` | the production URL, in `.env` | nothing boots: the value object refuses an unusable URI |

Rate limits live in `config/packages/rate_limiter.yaml`:

- **`mcp_write`** (20/hour) and **`mcp_import`** (30/hour), keyed on `<oauth client>:<user>` —
  **not** on the access token, which lives one hour and would hand a caller a fresh budget with
  every refresh.
- **`mcp_write_auth`** (10 failures/15 min, **per IP**, consumed only on failure). Its original job
  was blunting guesses at `MCP_WRITE_TOKEN` and expired with that secret — an RSA-signed JWT is not
  guessable. It is kept because refusing a bad token still costs a signature verification plus a
  revocation lookup in Postgres, so an anonymous caller can make this server work; it is consumed
  by `McpAuthenticationFailureListener`, which answers `429` once the window is empty.

They sit on the filesystem-backed `cache.rate_limiter` pool, so clearing `var/cache` on deploy
resets the windows.

The audit trail has its own Monolog channel, `mcp_audit`, declared with
`#[WithMonologChannel('mcp_audit')]` on the guard and writing to `var/log/mcp_audit.log` in dev and
prod alike. It is deliberately **outside** the prod `fingers_crossed` handler: that one buffers and
discards anything below `error`, which would silently drop every authorization record.

**It is a file, and `/app/var/log` is a persistent volume in production** — the trail has to outlive
the container. Do not move it to `php://stderr`; the prod error handlers are there, and the
difference is intentional.

## Testing

Every tool gets a test in `tests/Mcp/Tool/Read/` or `tests/Mcp/Tool/Write/`, mirroring the source
split. The transports are covered by `tests/Api/McpEndpointTest.php` (the public server) and
`tests/Api/McpAuthoringEndpointTest.php` (the authoring one). A new tool without a test is not done.

**Authorization can only be tested at the transport level**, which is why the 401, the 403, the
wrong-audience refusal and the draft quarantine all live in `McpAuthoringEndpointTest` rather than
in a tool test: a tool test cannot observe a status code, because an MCP tool error is a JSON-RPC
object inside a `200`. A write tool's own test sets an authenticated `OAuth2Token` in the token
storage — the state a call has already reached by the time the tool runs.

For a write tool the refusals are the point — cover each cap, and assert that a rejected payload
leaves **nothing** behind. `McpWriteGuardTest` and `SsrfGuardedFetcherTest`
are the models: both build their collaborators by hand with in-memory limiter storage, so the
limits under test are explicit rather than inherited from config. Integration tests get generous
limits via `when@test` so they never become order-dependent.
