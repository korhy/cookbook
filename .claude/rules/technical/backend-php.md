---
description: Backend conventions — PHP 8.4, Symfony 7.4, Doctrine ORM 3 on PostgreSQL, DI, typing.
paths:
  - "**/*.php"
  - "**/composer.json"
  - "**/composer.lock"
  - "**/config/**/*.yaml"
  - "**/config/**/*.php"
---

# Backend PHP / Symfony

## Base

- **PHP 8.4 is what actually runs.** `composer.json` declares `>=8.2`, but CI
  (`.github/workflows/ci.yml`), the OVH production host and the dev container all run **8.4**.
  Nothing validates 8.2, so treat 8.4 as the target and do not trust the `>=8.2` floor.
- `declare(strict_types=1)` at the top of **every** PHP file. **No file in `src/` has it today** —
  the codebase predates the rule. Add it to any file you touch, one file at a time; do not open a
  sweeping "add strict_types everywhere" change unless that is the task.
- Strict typing on parameters and returns; typed properties.
- `final` classes by default; open one only when it is genuinely meant to be extended. Doctrine
  entities are the standing exception (the proxy generator needs them non-final).
- Nullable-by-default Doctrine properties (`private ?string $title = null`) are the maker-bundle
  idiom this codebase follows. Keep it in entities; do **not** carry it into services.

## Dependency injection

- Constructor injection with promoted `readonly` properties. `App\Mcp\Tool\RecipeSearchTool` is the
  reference shape:
  ```php
  public function __construct(private readonly RecipeRepository $recipeRepository) {}
  ```
- Autowiring and autoconfiguration are on. Do not hand-register a service autowiring resolves.
- Environment variables reach services through `#[Autowire(env: '…')]` or container parameters —
  never `getenv()` or `$_ENV` in business code.

## Doctrine

- **ORM 3 on PostgreSQL 16.** Dev, CI and production all run Postgres — unlike Radiant, there is no
  SQLite-vs-Postgres split here. Dialect-specific SQL is therefore safe, but still prefer the
  QueryBuilder API.
- **Every query lives in a repository.** No `createQueryBuilder`, no `EntityManager` query and no
  `findBy` in a controller, a normalizer, an MCP tool or a Twig template. Callers use a named
  repository method — see `RecipeRepository::searchByKeywords()`.
- **Always bind parameters.** `setParameter()`, never concatenation into DQL. Both existing filters
  (`TitleFilter`, `IngredientFilter`) go through `QueryNameGeneratorInterface` to generate a
  collision-free parameter name — copy that pattern in any new filter.
- Migrations are generated (`make db-migration`), reviewed by hand, then applied (`make db-migrate`).
  **Read the generated SQL before committing it**: production migrations run non-interactively over
  SSH during deploy, so a destructive statement lands unattended.
- `make db-validate` must pass before a mapping change is considered done.

## Structure

- **No business logic in controllers.** Controllers orchestrate and delegate. The EasyAdmin CRUD
  controllers under `src/Controller/Admin/` are configuration, not logic — keep them that way.
- Business logic goes in `src/Service/`, custom constraints in `src/Validator/`, serialization
  concerns in `src/Serializer/`, Doctrine reactions in `src/EventListener/`.
- Behaviour that must apply everywhere (slugging, timestamps) belongs to a listener or a lifecycle
  callback, not to each caller.

## Commands

- Console commands use `#[AsCommand]` and stay thin: parse input, delegate, report.
- Long-running commands (`app:import-csv`) must batch and clear the entity manager or they exhaust
  memory. The existing `--batch-size` option is the pattern to follow.
