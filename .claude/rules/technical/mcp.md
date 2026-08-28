---
description: MCP server conventions — read-only tools under src/Mcp/Tool, argument handling, allowed_hosts, testing.
paths:
  - "**/src/Mcp/**/*.php"
  - "**/config/packages/mcp.yaml"
  - "**/config/routes/mcp.yaml"
  - "**/tests/Mcp/**/*.php"
---

# MCP server

`symfony/mcp-bundle` publishes the tools in `src/Mcp/Tool/` over stdio **and** HTTP at
**`/api/v1/mcp`**, which `security.yaml` grants `PUBLIC_ACCESS`.

## The contract

**Public, unauthenticated, read-only.** Three consequences, and none of them is negotiable:

1. **No tool may write.** No persist, no flush, no delete, no command dispatch. A tool that mutates
   state is a defect, not a feature — raise it with the user rather than implementing it.
2. **No tool may expose more than the REST API already does.** If a field is not in a `:read`
   serialization group, it does not belong in an MCP response either. Admin credentials, internal
   IDs of unexposed entities, file paths: never.
3. **Every argument is untrusted.** It arrives from an unauthenticated caller. It must reach the
   database only through a bound parameter, via a repository method.

## Writing a tool

`RecipeSearchTool`, `RecipeGetTool` and `CategoryListTool` are the templates. The shape:

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

## Testing

Every tool gets a test in `tests/Mcp/Tool/`, and the transport itself is covered by
`tests/Api/McpEndpointTest.php`. A new tool without a test is not done.
