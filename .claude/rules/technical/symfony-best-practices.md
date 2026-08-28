---
description: Official Symfony best practices, reconciled with this project's stack (API Platform, AssetMapper, EasyAdmin, a real translation catalogue).
paths:
  - "**/*.php"
  - "**/config/**/*.yaml"
  - "**/templates/**/*.twig"
  - "**/translations/**"
---

# Symfony best practices

> The official list: <https://symfony.com/doc/current/best_practices.html>. This file restates it
> **as rules for this repo** and flags the points where a deliberate project choice differs from the
> default. Where another rule covers a topic in more detail, follow that one — links per section.

## Project reconciliation (read first)

- **Assets → AssetMapper, not Webpack Encore.** `importmap.php` + `assets/` + `importmap('app')` in
  `base.html.twig`. There is **no `package.json`, no `node_modules`, no build step** — this is the
  opposite of Radiant, so do not import a Webpack habit here. New JS dependencies come in through
  `importmap:require`, and `make assets` compiles the map.
- **Internationalization is real here.** `translations/messages.{fr,en}.yaml` exist and are used.
  Unlike Radiant, do **not** write French inline in a template — go through the catalogue. See
  [naming.md](naming.md).
- **Writes go through EasyAdmin, not the API.** API Platform is configured read-only on purpose —
  see [api-platform.md](api-platform.md).

## Creating the project

- Keep the **default directory structure** (`src/`, `config/`, `templates/`, `public/`…). Do not
  invent custom top-level folders.

## Configuration

- **Environment variables for infrastructure config** — values that change per machine (database
  URL, mailer DSN, API credentials, `CORS_ALLOW_ORIGIN`). Real values in `.env.local`, never
  committed. See [security.md](security.md).
- **Parameters for application config** — values identical on every machine. Put them in
  `config/services.yaml` under a short `app.`-prefixed name to avoid collisions.
- **Constants for options that rarely change** — a class constant beats a parameter when the value
  is inherent to the class.
- Secrets: `.env.local` in dev and on the OVH host. Never a committed file.

## Business logic

- **Services are autowired and autoconfigured.** Do not hand-register what autowiring resolves.
- **Services should be private** — inject them, do not fetch them from the container.
- **Thin controllers.** `SecurityController` and the `Admin/` CRUD controllers are the model: they
  configure and delegate. Logic lives in `src/Service/`.
- **Repositories own the queries** — no query outside `src/Repository/`. See
  [backend-php.md](backend-php.md).

## Templates

- Twig templates in `templates/`, `snake_case` file names, mirroring the controller structure
  (`templates/admin/recipe/index.html.twig`).
- Prefer Twig extensions over PHP logic in a template; but the honest first question is whether the
  logic belongs in a service instead.
- The admin UI is EasyAdmin — override a **block**, never copy a whole upstream template. See
  [easyadmin.md](easyadmin.md).

## Forms

- One form type per class in `src/Form/`; `PascalCase` + `Type` suffix (`RecipeIngredientType`).
- **Add buttons in the template, not in the form class** — a form class reused across create and
  edit should not hardcode a submit label.
- Validation constraints go **on the entity or DTO**, not in the form type. `src/Validator/` holds
  the custom ones (`BanWord`).

## Security

- One firewall per authentication context — here `api` (stateless, JWT) and `admin` (form login).
- **`#[IsGranted]`, voters or `access_control`** — never a raw role-string comparison.
- See [security.md](security.md) for the access_control map and its ordering trap.

## Tests

- **Test the behaviour that matters**, not a coverage number. Here that means the `/api/v1`
  contract, the MCP tools, and the repository queries. See [testing.md](testing.md).
- Hardcode expected values in tests rather than recomputing them from the code under test.

## Deprecations

`phpunit.dist.xml` sets `failOnDeprecation="true"`. Symfony deprecations are **build failures**, by
design — fix them as they appear rather than accumulating an upgrade debt.
