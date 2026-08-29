---
description: API Platform 4 conventions — resources on entities, serialization groups, custom filters, pagination, the /api/v1 contract.
paths:
  - "**/src/Entity/**/*.php"
  - "**/src/Filter/**/*.php"
  - "**/src/Serializer/**/*.php"
  - "**/config/packages/api_platform.yaml"
  - "**/config/routes/api_platform.yaml"
  - "**/tests/Api/**/*.php"
---

# API Platform

## Where resources are declared

`#[ApiResource]` sits **on the Doctrine entity**, not on a separate DTO or an `ApiResource/` class.
`src/Entity/Recipe.php` is the reference implementation — read it before adding a resource.

Everything is served under **`/api/v1`**. Never hardcode the prefix in application code: it comes
from `config/routes/api_platform.yaml`.

## Read-only by design

`config/packages/api_platform.yaml` sets the project default operations to `GetCollection` + `Get`,
and nothing overrides that. **Do not add `Post`, `Put`, `Patch` or `Delete`** — content is authored
in EasyAdmin. If a write endpoint is genuinely needed, that is a design decision to raise with the
user first, not a change to slip into a feature.

`stateless: true` is on globally: no session, no server-side state between requests.

### Drafts never reach a consumer

`Recipe` carries a `status` (`draft` | `published`). `App\Doctrine\Extension\PublishedRecipeExtension`
implements both `QueryCollectionExtensionInterface` and `QueryItemExtensionInterface` and appends
`status = published` to every Recipe query, so a draft 404s on the item operation and is absent from
the collection.

It lives in a query extension rather than in `RecipeRepository` for a reason worth knowing: **API
Platform builds its own query builder and never calls the repository**, so a filter added there
would not protect the REST surface at all. Extensions are autoconfigured by their interfaces — no
manual tag.

If you add another resource with an unpublished state, it needs its own extension. And any test for
it belongs in `tests/Api/` — `RecipeDraftVisibilityTest` is the model.

## Serialization groups

- Every exposed property carries `#[Groups(['<resource>:read'])]`. **A property without it is
  invisible**, whatever the database says.
- Write groups (`recipe:write`) exist on the entity but no write operation consumes them. Leave them
  alone; do not treat them as the public surface.
- `normalization_context.skip_null_values: false` is set globally — nulls are serialized on purpose,
  so consumers can rely on a stable key set. Do not "clean up" null fields out of a payload.
- Relations serialize as IRIs by default (`/api/v1/categories/7`). Embedding an object instead is a
  contract change: it alters the shape Radiant parses.
- Custom normalizers live in `src/Serializer/` (`RecipeNormalizer`, `IngredientUnitNormalizer`).
  Reach for one only when attributes cannot express the need.

## Filters and query parameters

Filters are declared as `QueryParameter` entries in the operation, not as bare `#[ApiFilter]`:

```php
new GetCollection(parameters: [
    'title' => new QueryParameter(filter: new TitleFilter()),
    'order[duration]' => new QueryParameter(property: 'duration', filter: new OrderFilter()),
])
```

A custom filter implements `ApiPlatform\Doctrine\Orm\Filter\FilterInterface` and lives in
`src/Filter/`. Two non-negotiable points, both visible in `TitleFilter`:

1. Read the value from `$context['parameter']->getValue()` and **return early when it is null** — an
   absent parameter must not touch the query.
2. Generate the parameter name with `$queryNameGenerator->generateParameterName()` and bind it with
   `setParameter()`. Never interpolate a user value into the DQL string.

`getDescription()` is what documents the parameter in the OpenAPI output. Fill it in — it is the
only documentation a consumer gets.

## Pagination

`Recipe` sets `paginationItemsPerPage: 10`, `paginationMaximumItemsPerPage: 50`,
`paginationClientItemsPerPage: true`. The maximum is a real guard: the table holds 13k+ rows.

Anything that returns a collection must stay paginated. A custom endpoint or a repository method
that returns "all recipes" is a defect.

## Documentation and testing

- `/api/v1/docs` is `PUBLIC_ACCESS`; every other `/api` path requires `ROLE_ADMIN` — see
  [security.md](security.md).
- API behaviour is tested under `tests/Api/` (`RecipePaginationTest`, `McpEndpointTest`). **A new
  filter, parameter or exposed field needs a test there**, because Radiant is the thing that breaks
  when the shape drifts and nothing else will catch it.
