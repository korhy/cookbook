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
  - "**/tests/Service/Mcp/**/*.php"
  - "**/tests/Service/Http/**/*.php"
---

# MCP server

`symfony/mcp-bundle` publishes the tools in `src/Mcp/Tool/` over stdio **and** HTTP at
**`/api/v1/mcp`**, which `security.yaml` grants `PUBLIC_ACCESS`.

## The contract

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
(`recipe_search` caps at 5, `ingredient_search` at 10) — a tool that can return the whole 13k-row
table is a defect. They see **published recipes only**; `RecipeRepository::searchByKeywords()` and
`findOneBySlug()` filter on status for that reason.

### Write tools — the sanctioned exception, not a precedent

`recipe_create` and `recipe_import_from_url`. These exist because writing recipes from an MCP client
was a deliberate product decision, taken with the security design below. **Adding a third is a
decision for the user, not a step in a feature.** Any new one must reuse every layer:

1. **`McpWriteGuard` first, before anything else happens.** Call `assertMayWrite()` (or
   `assertMayFetch()` for outbound requests) as the first statement. It throws; it does not return a
   status, precisely so that forgetting to check is not something you can do by accident.
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
6. **Audit, without leaking.** The guard logs to the `mcp_audit` channel. It records an 8-character
   SHA-256 fingerprint of the token — **never the token**.

Refusal messages are uniform on purpose: "disabled", "wrong token" and "locked out" all return
`Write access denied.`, because distinguishing them tells an attacker whether a write surface
exists. `UrlFetchRejectedException` is vague about *why* a host was refused for the same reason —
naming the resolved address would make the importer an internal network scanner.

### The safe default

With **`MCP_WRITE_TOKEN` unset or shorter than 32 characters**, both write tools refuse every call.
With **`MCP_IMPORT_ALLOWED_HOSTS` empty**, no URL can be fetched at all. Both are empty in the
committed `.env`, so a deploy never turns this into a write surface by itself — enabling it is a
deliberate act in `.env.local` on the server.

They are still listed in `tools/list` when disabled. That is a limitation, not an oversight: the
`McpTool` attribute is autoconfigured at container-compile time and the token is a runtime value,
so the tool cannot be conditionally unregistered. It hard-refuses instead.

## Writing a tool

`RecipeSearchTool`, `RecipeGetTool` and `CategoryListTool` are the templates for a read tool;
`RecipeCreateTool` is the template for a write one. The read shape:

```php
#[McpTool(
    name: 'recipe_search',
    description: 'Search for recipes by keyword… Returns up to 5 matches.',
)]
class RecipeSearchTool
{
    public function __construct(private readonly RecipeRepository $recipeRepository) {}

    /** @return array{recipes: array<int, array{id: ?int, title: ?string, …}>} */
    public function __invoke(string $keywords): array { … }
}
```

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

`config/packages/mcp.yaml` restricts `allowed_hosts` per environment:

| Env | Hosts |
|---|---|
| prod | `api.clementboudinel.fr` |
| dev / test | `localhost`, `127.0.0.1`, `cookbook_app` |

A tool that "does not answer" from inside a container is almost always a `Host` header missing from
that list — check it before debugging the tool itself.

Sessions are file-backed under `%kernel.cache_dir%/mcp-sessions` with a 3600s TTL. `make cc` wipes
them; that is expected.

## Configuration for the write path

| Variable | Default | Effect when unset |
|---|---|---|
| `MCP_WRITE_TOKEN` | empty | Both write tools refuse every call |
| `MCP_IMPORT_ALLOWED_HOSTS` | empty | `recipe_import_from_url` fetches nothing |

Rate limits live in `config/packages/rate_limiter.yaml`: `mcp_write` (20/hour, per token
fingerprint), `mcp_import` (30/hour), `mcp_write_auth` (10 failures/15 min, **per IP**, consumed
only on failure so ordinary use never locks a client out). They sit on the filesystem-backed
`cache.rate_limiter` pool, so clearing `var/cache` on deploy resets the windows.

The audit trail has its own Monolog channel, `mcp_audit`, writing to `var/log/mcp_audit.log`. It is
deliberately **outside** the prod `fingers_crossed` handler: that one buffers and discards anything
below `error`, which would silently drop every authorization record.

## Testing

Every tool gets a test in `tests/Mcp/Tool/`, and the transport itself is covered by
`tests/Api/McpEndpointTest.php`. A new tool without a test is not done.

For a write tool the refusals are the point — cover no token, wrong token, and each cap, and assert
that a rejected payload leaves **nothing** behind. `McpWriteGuardTest` and `SsrfGuardedFetcherTest`
are the models: both build their collaborators by hand with in-memory limiter storage, so the
limits under test are explicit rather than inherited from config. Integration tests get generous
limits via `when@test` so they never become order-dependent.
