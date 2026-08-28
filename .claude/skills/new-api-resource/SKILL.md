---
name: new-api-resource
description: Scaffold an API Platform read resource end to end — entity with #[ApiResource], serialization groups, repository, custom filters as QueryParameters, migration, EasyAdmin CRUD and tests under tests/Api. Use when the user asks to expose an entity over the API, add a resource/endpoint/filter to /api/v1, or "/new-api-resource".
---

# /new-api-resource — expose a resource on `/api/v1`

Scaffolds a resource on the Cookbook API following the shape already in place. `src/Entity/Recipe.php`
is the reference implementation — read it before writing anything.

> **This API has an external consumer.** Radiant reads it
> (`radiant/src/Service/Cookbook/CookbookApiService.php`). Everything you expose becomes a contract.
> See `.claude/rules/business/cookbook.md` and `.claude/rules/technical/api-platform.md`.

## 0. Decide the scope

- **New entity, or expose an existing one?** If the entity exists, most of steps 1–2 are already
  done — skip to the `#[ApiResource]` attribute.
- **Read-only.** The project default is `GetCollection` + `Get` and nothing overrides it. If the
  request implies `POST`/`PUT`/`DELETE`, stop and raise it: content is authored in EasyAdmin, and
  opening writes over HTTP is a design decision for the user.
- **Which fields are public?** Ask if it is not obvious. A field with no `#[Groups]` is invisible;
  a field wrongly added to a read group is a leak you cannot take back once a consumer depends on it.

## 1. Entity

`src/Entity/<Name>.php`, `declare(strict_types=1)` at the top (new files get it even though the
legacy entities predate the rule).

- Doctrine mapping attributes; `#[UniqueEntity]` where a natural key exists.
- If it has a slug: implement `App\Entity\SluggableInterface` and let `SlugListener` populate it.
  **Never set a slug by hand.**
- If it needs timestamps: `#[ORM\HasLifecycleCallbacks]` with `#[ORM\PrePersist]` /
  `#[ORM\PreUpdate]`, as `Recipe` does.
- Validation constraints on the entity (`Assert\*`, or a custom one in `src/Validator/`).
- Ordered collections carry `#[ORM\OrderBy]` at the mapping level, like `Recipe::$instructions` —
  so consumers get the order for free.

## 2. Repository

`src/Repository/<Name>Repository.php`, extending `ServiceEntityRepository`.

**Every query lives here.** Named methods, bound parameters, `leftJoin` + `addSelect` for relations
that will be serialized (otherwise API Platform fires N+1 queries per collection page).

## 3. The `#[ApiResource]` attribute

```php
#[ApiResource(
    normalizationContext: ['groups' => ['<name>:read']],
    denormalizationContext: ['groups' => ['<name>:write']],
    paginationEnabled: true,
    paginationItemsPerPage: 10,
    paginationMaximumItemsPerPage: 50,
    paginationClientItemsPerPage: true,
    paginationClientEnabled: true,
    operations: [
        new GetCollection(parameters: [ /* step 4 */ ]),
        new Get(),
    ],
)]
```

- Tag each exposed property with `#[Groups(['<name>:read'])]`.
- `#[ApiProperty(example: '…')]` on the non-obvious ones — it is what the OpenAPI docs show.
- Keep `paginationMaximumItemsPerPage` capped. The recipe table holds 13k+ rows.
- Relations serialize as IRIs by default. Embedding an object instead is a contract shape decision —
  make it deliberately, and say so.

## 4. Filters and query parameters

Declare them as `QueryParameter` entries on the operation, the way `Recipe` does:

```php
'title'         => new QueryParameter(filter: new TitleFilter()),
'category'      => new QueryParameter(property: 'category', filter: new IriFilter()),
'order[slug]'   => new QueryParameter(property: 'slug', filter: new OrderFilter()),
```

Reuse a built-in filter (`IriFilter`, `OrderFilter`, `SearchFilter`) whenever one fits. Write a
custom one in `src/Filter/` only when none does — `TitleFilter` is the template, and two of its
details are mandatory:

1. read `$context['parameter']->getValue()` and **return early on `null`** — an absent parameter must
   not touch the query;
2. name the parameter with `$queryNameGenerator->generateParameterName()` and bind it with
   `setParameter()`. **Never interpolate a user value into DQL.**

Fill in `getDescription()`: it is the only documentation the consumer gets.

## 5. Migration

```bash
make db-migration    # generate
# READ the generated SQL in migrations/ before committing it
make db-migrate      # apply
make db-validate     # mapping matches the schema
```

Production migrations run **non-interactively over SSH** during deploy, so review the SQL: a
destructive statement lands unattended.

## 6. EasyAdmin

If the entity is authored by a human, it needs a back-office — the API cannot write.

- `src/Controller/Admin/<Name>Controller.php` extending `AbstractCrudController`.
- **Add it to `configureMenuItems()` in `DashboardController`** — a CRUD controller that is not in
  the menu is invisible. This is the step most often forgotten.

See `.claude/rules/technical/easyadmin.md` for the field patterns (join entities, uploads, ordered
collections).

## 7. Tests

`tests/Api/<Name>Test.php` — this is not optional, it is the only thing standing between a refactor
and a broken Radiant:

- the collection is **paginated** and respects `itemsPerPage` and its maximum;
- **each filter** narrows the result as documented;
- the item response **shape** — assert on keys, not just on a 200;
- authentication: `^/api` requires `ROLE_ADMIN`, so an anonymous request must get **401**.

`tests/Api/RecipePaginationTest.php` is the model.

## 8. Verify

```bash
make lint
make db-test && make phpunit
make console C="debug:router" | grep api      # the routes exist
```

Then over HTTP, with a token:

```bash
TOKEN=$(curl -s -X POST http://localhost:8001/api/login_check \
  -H 'Content-Type: application/json' -d '{"username":"admin","password":"<password>"}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
curl -s -H "Authorization: Bearer $TOKEN" 'http://localhost:8001/api/v1/<name>s?page=1&itemsPerPage=2'
```

`http://localhost:8001/api/v1/docs` should show the resource, its filters and their descriptions.

## 9. Report

State the routes added, the exposed fields, the filters and the pagination cap. If anything about
the change is **breaking** for an existing consumer, say so plainly and use `feat!:` /
`BREAKING CHANGE` in the commit subject — the release tagging reads it.
