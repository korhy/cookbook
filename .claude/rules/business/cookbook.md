---
description: Project-specific business rules — domain model, the public API contract consumed by Radiant, the MCP server, CSV import, folder conventions.
paths:
  - "**/*"
---

# Cookbook — business rules

**Cookbook** is a Symfony 7.4 recipe application. It is two things at once, and the second one is
what makes it different from a standard CRUD app:

1. A **back-office** (EasyAdmin 4) where recipes are authored, plus a bulk CSV importer.
2. A **read API** (API Platform 4.2) and an **MCP server** — both consumed by *other applications*,
   chiefly the **Radiant** portfolio.

That second role is the constraint that governs everything else: **this project has external
consumers, so its API surface is a contract, not an implementation detail.**

## Domain model (entities under `src/Entity`)

- **Recipe** — `title`, `slug`, `description`, `duration` (minutes), `thumbnail`, `createdAt`,
  `updatedAt`. Implements `SluggableInterface`. `#[ORM\HasLifecycleCallbacks]` stamps `createdAt`
  on persist and `updatedAt` on update. Thumbnails go through **VichUploader**
  (`recipe_thumbnail` mapping); the entity exposes a non-persisted `thumbnailFile` alongside the
  persisted `thumbnail` filename.
- **Category** — `name`, `slug`. One category, many recipes.
- **Ingredient** — `name`, `slug`. Reached through `RecipeIngredient`, never directly.
- **RecipeIngredient** — the join entity carrying `quantity` and a `unit`
  (`App\Enum\IngredientUnit`). This is why "add an ingredient to a recipe" is never a plain
  many-to-many.
- **Instruction** — `content` + `position`, ordered by `position` ASC at the mapping level
  (`#[ORM\OrderBy]`), so consumers get the steps in order without sorting them.
- **Admin** — the single login account (`username`, `roles`, `password`). The provider in
  `security.yaml` resolves on **`username`**, not on an email.

Slugs are produced by `App\Service\SluggerService`, wired through `App\EventListener\SlugListener`
for entities implementing `SluggableInterface`. **Never set a slug by hand** in application code —
go through the listener.

## The API contract (`/api/v1`)

Everything is versioned under **`/api/v1`**. Resources are declared with `#[ApiResource]` **on the
entities themselves** — there is no separate DTO layer.

Only **read** operations are exposed. `config/packages/api_platform.yaml` sets the project-wide
default to `GetCollection` + `Get`, and `Recipe` re-states it explicitly. **The API is read-only:
writing happens through EasyAdmin, not over HTTP.**

`Recipe`'s collection operation publishes these query parameters, and they are part of the contract:

| Parameter | Filter | Behaviour |
|---|---|---|
| `title` | `App\Filter\TitleFilter` | case-insensitive partial match |
| `category` | `IriFilter` | filter by category **IRI**, e.g. `/api/v1/categories/7` |
| `ingredient` | `App\Filter\IngredientFilter` | filter by ingredient |
| `order[slug]`, `order[duration]`, `order[createdAt]` | `OrderFilter` | sorting |
| `page`, `itemsPerPage` | pagination | 10 per page by default, **50 maximum** |

Serialization runs through the `recipe:read` / `recipe:write` groups. **A property with no
`#[Groups]` attribute is invisible to consumers** — that is the most common cause of "the field is
in the database but Radiant doesn't see it".

### Rules for changing the API

- **Removing or renaming an exposed field, a filter or a query parameter is a breaking change.**
  Radiant consumes it (`radiant/src/Service/Cookbook/CookbookApiService.php`). Say so explicitly in
  the plan and in the commit, and use `feat!:` / `BREAKING CHANGE` so the tag lands on major.
- Adding a field to a `read` group is safe. Adding one to `write` is not — writes are not exposed.
- `paginationMaximumItemsPerPage: 50` guards against a consumer asking for the 13k+ recipes in one
  request. Do not raise it casually.

## The MCP server

`symfony/mcp-bundle` exposes the tools in `src/Mcp/Tool/` at **`/api/v1/mcp`**
(`config/routes/mcp.yaml` prefixes the bundle's `/mcp` path with `/api/v1`; the route is granted
`PUBLIC_ACCESS` in `security.yaml`).

Three tools ship today: `recipe_search`, `recipe_get`, `category_list`.

**The MCP server is public and read-only.** No tool may write, delete, or expose anything the REST
API does not already publish. It is unauthenticated — treat every argument as untrusted input and
never let one reach a query unparameterised.

`allowed_hosts` is restricted per environment in `config/packages/mcp.yaml`
(`api.clementboudinel.fr` in prod; `localhost`, `127.0.0.1` and `cookbook_app` in dev/test). A tool
that "does not respond" from a container is usually a host missing from that list.

## CSV import

`App\Command\ImportCsvCommand` (`app:import-csv`) bulk-loads `public/data/*.csv`. It is
memory-hungry — the dev container sets `memory_limit=1024M` for that reason. Use
`make import-csv ARGS="--dry-run"` before a real run. `public/data/` is git-ignored: the CSVs are
not part of the repository.

## Folder conventions

```
src/
├── Command/        # console commands (ImportCsvCommand)
├── Controller/     # SecurityController + Admin/ (EasyAdmin CRUD controllers)
├── DTO/
├── Entity/         # Doctrine entities — and where #[ApiResource] lives
├── Enum/           # IngredientUnit
├── EventListener/  # SlugListener, LocaleListener
├── Filter/         # custom API Platform filters
├── Form/           # form types used by EasyAdmin
├── Mcp/Tool/       # MCP tools — read-only
├── Repository/     # every Doctrine query lives here
├── Serializer/     # custom normalizers
├── Service/        # business logic
└── Validator/      # custom constraints (BanWord)
```
