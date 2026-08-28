![CI](https://github.com/korhy/cookbook/actions/workflows/ci.yml/badge.svg)
![Deploy](https://github.com/korhy/cookbook/actions/workflows/deploy.yml/badge.svg)
![Version](https://img.shields.io/github/v/tag/korhy/cookbook?label=version)

# Cookbook

A Symfony recipe application: recipes are authored in an **EasyAdmin** back-office and published
through a **read-only REST API** built with [API Platform](https://api-platform.com/) and a
**public MCP server**. Recipes can be bulk-imported from CSV.

It is designed to be consumed. The **Radiant** portfolio is its first-class client — Radiant's
"Cookbook" mini-app reads this API and stores no recipe of its own — which is why the `/api/v1`
surface is treated as a contract rather than an implementation detail.

## Features

- **Recipes** — title, slug, description, duration, thumbnail, ordered instructions, and ingredients
  carrying a quantity and a unit through a dedicated join entity.
- **Read API** (`/api/v1`) — JSON-LD, JWT-authenticated, paginated, with filtering on title,
  category and ingredient, and sorting on slug, duration and creation date.
- **MCP server** (`/api/v1/mcp`) — public read tools (`recipe_search`, `recipe_get`,
  `category_list`, `ingredient_search`) so an AI client can query the catalogue, plus two
  **token-gated** tools (`recipe_create`, `recipe_import_from_url`). Writes land as unpublished
  **drafts** and stay invisible to the API until approved in the back-office; with no token
  configured they refuse every call, which is the default.
- **Back-office** (`/admin`) — EasyAdmin 4, where all content is edited and where MCP-submitted
  drafts are reviewed and published.
- **CSV import** — `app:import-csv` bulk-loads a whole recipe catalogue.

## API

| Endpoint | Auth | Description |
|---|---|---|
| `POST /api/login_check` | public | exchange credentials for a JWT |
| `GET /api/v1/docs` | public | OpenAPI / Swagger documentation |
| `GET /api/v1/mcp` | public | MCP server |
| `GET /api/v1/recipes` | `ROLE_ADMIN` | paginated recipe collection |
| `GET /api/v1/recipes/{id}` | `ROLE_ADMIN` | a single recipe |
| `GET /api/v1/categories`, `/ingredients`, `/instructions`, `/recipe_ingredients` | `ROLE_ADMIN` | the other resources |
| `GET /admin` | `ROLE_ADMIN` | EasyAdmin dashboard |
| `GET|POST /login` | public | admin sign-in |

Only `/api/login_check`, `/api/v1/docs` and `/api/v1/mcp` are public. **Everything else under
`/api` requires an authenticated admin.**

### Recipe collection parameters

| Parameter | Behaviour |
|---|---|
| `title` | case-insensitive partial match |
| `category` | filter by category IRI, e.g. `/api/v1/categories/7` |
| `ingredient` | filter by ingredient |
| `order[slug]`, `order[duration]`, `order[createdAt]` | sorting (`asc` / `desc`) |
| `page`, `itemsPerPage` | pagination — 10 per page by default, **50 maximum** |

### Getting a token

```bash
curl -X POST http://localhost:8001/api/login_check \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "your_password"}'
```

### Using it

```bash
curl -H "Authorization: Bearer <token>" \
  'http://localhost:8001/api/v1/recipes?page=1&itemsPerPage=10&order[duration]=asc'
```

## Getting started

### Prerequisites

**Docker and Docker Compose — that is all.** PHP, Composer and the database all run in containers;
you do not need a local PHP installation, and there is no `symfony serve` in this project.

### Install

```bash
git clone https://github.com/korhy/cookbook.git
cd cookbook
cp .env .env.local        # then fill in the real values
```

> `.env` holds **non-secret defaults only** and is committed on purpose. Real values — the database
> credentials, `APP_SECRET`, `JWT_PASSPHRASE` — go in `.env.local`, which is git-ignored. Never put
> a secret in `.env`.

```bash
make up
```

That single command builds the image and starts everything. On first boot the container installs the
Composer dependencies, waits for PostgreSQL, generates the JWT keypair and bootstraps the schema.
Follow it with `make logs`.

| Service | URL |
|---|---|
| API | http://localhost:8001 |
| API documentation | http://localhost:8001/api/v1/docs |
| Back-office | http://localhost:8001/admin |
| Adminer (database UI) | http://localhost:8083 |
| Mailpit (caught emails) | http://localhost:8025 |
| PostgreSQL | `localhost:5435` |

### Create the admin account

```bash
make admin        # prints a password hash — copy it
make console C="dbal:run-sql \"INSERT INTO admin (id, username, roles, password) VALUES (nextval('admin_id_seq'), 'admin', '[\\\"ROLE_ADMIN\\\"]', 'THE_HASH');\""
```

### Import a recipe catalogue (optional)

Place the CSV files in `public/data/`, then:

```bash
make import-csv ARGS="--dry-run"   # preview
make import-csv                    # for real
```

| File | Format |
|---|---|
| `recipe_categories.csv` | `name,id` |
| `ingredients.csv` | `name,id` |
| `recipes_final.csv` | `id,recipe_title,description,id_category` |
| `recipe_ingredients.csv` | `id_recipe,quantity,id_unit,id_ingredient` |
| `recipe_instructions.csv` | `id_recipe,content,position` |

Options: `--skip-header`, `--batch-size=50`, `--dry-run`, `--delimiter=";"`.

## Using it from Radiant

Radiant runs in its own Docker stack, so it cannot reach this API on `127.0.0.1:8001` — inside a
container that address resolves to the container itself. The two stacks are joined by an **external
Docker network, `korhy_net`**, and Radiant addresses this one by container name:

```dotenv
# radiant/.env.local
COOKBOOK_API_URL=http://cookbook_app
COOKBOOK_API_USERNAME=admin
COOKBOOK_API_PASSWORD=…
COOKBOOK_API_VERSION=v1
```

`make net` — a prerequisite of `make up` on both sides — creates the network if it is missing. It is
declared `external` on purpose: it survives `make down` on either side, and neither project needs
the other to be running. When Cookbook is down, Radiant degrades gracefully instead of erroring.

To check the link from Radiant:

```bash
docker compose exec app curl -s -o /dev/null -w '%{http_code}\n' http://cookbook_app/api/v1
```

## Commands

`make help` lists everything. The common ones:

```bash
make up / down / logs / sh      # the stack
make cc                         # clear the Symfony cache
make console C="debug:router"   # any console command
make db-migration / db-migrate  # generate / apply migrations
make db-test                    # create and migrate the app_test database
make psql                       # psql shell
make jwt-keys                   # (re)generate the Lexik keypair
make assets                     # importmap:install + asset-map:compile
make lint                       # php-cs-fixer + PHPStan
make phpunit                    # the test suite (TEST=path for a subset)
make ci                         # exactly what CI runs
```

Every PHP target runs inside the app container, so `make up` first.

## Testing

```bash
make db-test     # once, and after any new migration
make phpunit
make phpunit TEST=tests/Api/RecipePaginationTest.php
```

Tests run on **PostgreSQL**, like CI and like production, against a dedicated `app_test` database.

> The container exports `APP_ENV=dev` and a dev `DATABASE_URL` as real environment variables, which
> beat `.env.test`. `make phpunit` overrides them explicitly — do not call `bin/phpunit` directly
> inside the container, or the suite will silently run against the development database.

The suite covers the API contract (`tests/Api`), the MCP tools (`tests/Mcp`), the repositories,
entities, services and the login controller. `phpunit.dist.xml` sets `failOnDeprecation`,
`failOnNotice` and `failOnWarning` to `true`: **a deprecation fails the build, by design.**

## Quality gates

| Gate | Scope |
|---|---|
| PHP-CS-Fixer | `@Symfony` ruleset over the PHP sources |
| PHPStan | level 5 over `bin/ config/ public/ src/ tests/` |
| PHPUnit | API, MCP, repositories, entities, services, security |

There is **no Twig linter and no JS/CSS linter** in this project — review those by hand.

> CI runs **PHP 8.4**, and so do the dev container and the production host. `composer.json` declares
> `>=8.2`, but nothing validates 8.2.

## Deployment

Production is **OVH mutualisé — not Docker.** Docker is a local development tool here only.

Every green CI run on `main` triggers `deploy.yml`, which connects over SSH, pulls, installs the
production dependencies, runs the migrations non-interactively, compiles the asset map and clears
the cache. The full procedure and the OVH-specific traps are in **[DEPLOY.md](DEPLOY.md)**.

## Project structure

```
├── .claude/            # Claude Code configuration (rules, skills) — see CLAUDE.md
├── config/             # Symfony configuration
│   ├── packages/       # api_platform, security, mcp, doctrine…
│   └── routes/         # api_platform.yaml, mcp.yaml, easyadmin.yaml…
├── docker/frankenphp/  # the local dev image (Dockerfile, Caddyfile, entrypoint)
├── migrations/         # Doctrine migrations
├── public/             # front controller; data/ (git-ignored) holds the import CSVs
├── src/
│   ├── Command/        # app:import-csv
│   ├── Controller/     # SecurityController + Admin/ (EasyAdmin CRUD)
│   ├── Entity/         # Doctrine entities — and where #[ApiResource] lives
│   ├── Enum/           # IngredientUnit
│   ├── EventListener/  # SlugListener, LocaleListener
│   ├── Filter/         # custom API Platform filters
│   ├── Form/           # form types used by EasyAdmin
│   ├── Mcp/Tool/       # MCP tools — read tools + the token-gated write tools
│   ├── Repository/     # every Doctrine query lives here
│   ├── Serializer/     # custom normalizers
│   ├── Service/        # business logic
│   └── Validator/      # custom constraints
├── templates/          # Twig (admin overrides, security)
├── tests/              # Api, Controller, Entity, Mcp, Repository, Service
└── translations/       # messages.fr.yaml / messages.en.yaml
```
