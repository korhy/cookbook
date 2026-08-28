---
description: Testing — PHPUnit 12 on PostgreSQL, the app_test database, what must be covered, the strict failure settings.
paths:
  - "**/tests/**/*.php"
  - "**/phpunit.dist.xml"
  - "**/.env.test"
---

# Testing

## Running the suite

```bash
make db-test     # create app_test and migrate it — once, and after any new migration
make phpunit     # run everything   (TEST=tests/Api/RecipePaginationTest.php for a subset)
make ci          # exactly what .github/workflows/ci.yml runs
```

**Tests run on PostgreSQL, like production and like CI.** There is no SQLite shortcut here, so a
test may lean on Postgres behaviour without lying about production. That is a real advantage over
the Radiant setup — use it, but keep queries in repositories anyway.

### The environment trap

The dev container exports `APP_ENV=dev` and a dev `DATABASE_URL` as **real environment variables**,
and those win over `.env.test`. Run PHPUnit without overriding them and the test kernel boots in
`dev` against the **development database** — the suite appears to pass while testing the wrong
thing, and fixtures can damage dev data.

The `phpunit` and `db-test` Make targets pass `-e APP_ENV=test -e DATABASE_URL=…app_test…`
explicitly for that reason. **Never invoke `bin/phpunit` directly inside the container**; go through
`make`.

## What the suite covers

```
tests/
├── Api/          # RecipePaginationTest, McpEndpointTest — the public contract
├── Controller/   # SecurityControllerTest — login
├── Entity/       # RecipeTest — entity behaviour
├── Mcp/Tool/     # one test per MCP tool
├── Repository/   # RecipeRepositoryTest — the queries
└── Service/      # SluggerServiceTest
```

Coverage is **deliberately partial** — the point is the risky surface, not a percentage. What must
always be tested:

1. **Anything on the `/api/v1` contract.** A new exposed field, filter, query parameter or
   pagination change needs a test under `tests/Api/`. Radiant is what breaks when the shape drifts,
   and nothing else in this repository will catch it.
2. **Every MCP tool** — `tests/Mcp/Tool/`. They are public and unauthenticated.
3. **Every repository query** with non-trivial logic (joins, `LIKE`, ordering, limits).
4. **Every custom filter and validator.**

Entity getters and setters do not need tests. EasyAdmin `configureFields()` does not either.

## Conventions

- `KernelTestCase` for services and repositories, `WebTestCase` for HTTP, API Platform's
  `ApiTestCase` for the API surface.
- Assert on the **response shape**, not just the status code — the shape *is* the contract.
- Tests are English-named and describe behaviour: `testRecipeCollectionIsPaginated`, not `testGet`.

## The strict settings

`phpunit.dist.xml` sets `failOnDeprecation`, `failOnNotice` and `failOnWarning` to `true`, with
`restrictNotices` and `restrictWarnings` over the `src` source set.

**A deprecation fails the build.** That is intentional: it is what keeps the Symfony 7.4 upgrade
path clean. Fix the deprecation — do not silence it, and do not relax these flags to get green.

## JWT in tests

The API firewall needs a keypair. CI generates a fresh one with `JWT_PASSPHRASE=test`, so it can pin
the passphrase. **Locally the Make targets deliberately do not override `JWT_PASSPHRASE`**: the keys
in `config/jwt/` were generated with the value from `.env.local`, and forcing another one fails every
API test with an opaque "error while trying to encode the JWT token". A suite failing on a Lexik key
error means the keypair is missing — run `make jwt-keys`.
