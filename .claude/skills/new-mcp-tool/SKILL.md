---
name: new-mcp-tool
description: Scaffold a read-only MCP tool in src/Mcp/Tool — the McpTool attribute, a typed invoke signature, a repository-backed query, the array shape and its PHPUnit test. Use when the user asks to add an MCP tool, expose something to MCP clients, extend the MCP server, or "/new-mcp-tool".
---

# /new-mcp-tool — add a tool to the Cookbook MCP server

Adds one tool to the MCP server that `symfony/mcp-bundle` publishes at `/api/v1/mcp`.

> **Read this first.** The MCP endpoint is **public and unauthenticated** (`PUBLIC_ACCESS` in
> `security.yaml`). A tool is world-readable and every argument is untrusted. The contract in
> `.claude/rules/technical/mcp.md` is not advisory.

## 0. Refuse the wrong shape

Before writing anything, check the request against the contract:

- **The tool must not write.** No persist, flush, delete or command dispatch. If the request implies
  a mutation, stop and say so: authoring happens in EasyAdmin, and making the public MCP endpoint
  able to change data is a security decision for the user, not an implementation detail.
- **The tool must not expose more than the REST API does.** If the data is not in a `:read`
  serialization group, it does not belong here either.
- **The result must be bounded.** There are 13k+ recipes; a tool that can return them all is a
  defect. Decide the cap now (`recipe_search` uses 5).

If any of these is in doubt, raise it before generating code.

## 1. Gather

- **Tool name** — `snake_case`, English, `<entity>_<verb>` (`recipe_search`, `category_list`).
  It is a public identifier: renaming it later breaks every configured client.
- **Arguments** — name, type, meaning. Every one is typed on `__invoke()`; the bundle derives the
  input schema from the signature.
- **What it returns**, and the cap.

## 2. Read the existing tools

Do not invent a shape. `src/Mcp/Tool/` holds three worked examples:

- `CategoryListTool` — no argument, full (small) list.
- `RecipeSearchTool` — one string argument, capped result set.
- `RecipeGetTool` — lookup by identifier.

Match whichever is closest.

## 3. The repository method

**The query belongs in the repository, never in the tool.** Add or reuse a method on the relevant
repository under `src/Repository/`:

- bind every parameter with `setParameter()` — the argument is untrusted input;
- `setMaxResults()` for anything collection-shaped;
- `leftJoin` + `addSelect` for relations the tool reads, so it does not fire N+1 queries.

`RecipeRepository::searchByKeywords()` is the reference.

## 4. The tool class

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Recipe;
use App\Repository\RecipeRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'recipe_search',
    description: 'Search for recipes by keyword, matching against title, description, or category name. Returns up to 5 matches.',
)]
final class RecipeSearchTool
{
    public function __construct(private readonly RecipeRepository $recipeRepository)
    {
    }

    /**
     * @return array{recipes: array<int, array{id: ?int, title: ?string, slug: ?string}>}
     */
    public function __invoke(string $keywords): array
    {
        return ['recipes' => array_map(
            static fn (Recipe $recipe): array => [
                'id' => $recipe->getId(),
                'title' => $recipe->getTitle(),
                'slug' => $recipe->getSlug(),
            ],
            $this->recipeRepository->searchByKeywords($keywords),
        )];
    }
}
```

Four things that are not optional:

1. **`declare(strict_types=1)`** — new files get it, even though the legacy tools predate the rule.
2. **The `description` is the only thing a model reads** when deciding to call the tool. State what
   it returns *and its limits*, as the example does with "Returns up to 5 matches."
3. **The PHPDoc array shape.** PHPStan level 5 covers `src/`, and the shape is what keeps the
   response honest.
4. **Map entities to plain arrays in the tool.** Never return an entity — it would serialize
   whatever the ORM hands over, including fields the API does not expose.

Autoconfiguration registers it: no manual service declaration.

## 5. Test it

Add `tests/Mcp/Tool/<Name>ToolTest.php`, following the existing tests in that directory. Cover:

- the nominal case;
- an argument that matches nothing (empty result, not an error);
- **the cap** — that a broad query returns at most N items.

`tests/Api/McpEndpointTest.php` covers the transport; you do not need to duplicate it.

## 6. Verify

```bash
make up
make console C="debug:container --tag=mcp.tool"    # the tool is registered
make lint                                          # php-cs-fixer + PHPStan
make db-test && make phpunit
```

Then exercise it over HTTP. Note that `allowed_hosts` in `config/packages/mcp.yaml` restricts the
`Host` header — from the host machine use `localhost:8001`; a request arriving with an unlisted host
is rejected before it reaches the tool.

## 7. Report

Tell the user the tool name, its arguments, its cap, and that it is reachable at
`/api/v1/mcp`. If a client (Claude Code's `.mcp.json`, or another consumer) needs to be
reconfigured to see it, say so — this repository does not do that for them.
