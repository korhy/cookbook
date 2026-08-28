---
description: Naming conventions — English identifiers and comments everywhere, framework casing rules, the translation catalogue for user-facing text.
paths:
  - "**/*.php"
  - "**/*.twig"
  - "**/*.js"
  - "**/config/**/*.yaml"
  - "**/translations/**"
---

# Naming conventions

> **All code identifiers are in English.** French is reserved for **user-facing text only** —
> and in this project that text goes through the **translation catalogue**, not inline in a
> template (see the last section; this is the one place cookbook differs from Radiant).

## English, always — the code

| Kind | Convention | Example in this repo |
|---|---|---|
| Class / interface / enum | `PascalCase`, English | `RecipeSearchTool`, `SluggableInterface`, `IngredientUnit` |
| Method / function | `camelCase`, verb-first | `searchByKeywords()`, `findOneBySlug()` |
| Variable / property / argument | `camelCase`, English | `$recipeIngredients`, `$createdAt` |
| Constant / enum case | `UPPER_SNAKE_CASE` | `ROLE_ADMIN` |
| Console command | `app:kebab-case` | `app:import-csv` |
| MCP tool `name` | `snake_case`, English | `recipe_search`, `category_list` |
| API resource path | plural, snake_case | `/api/v1/recipes`, `/api/v1/recipe_ingredients` |
| Query parameter | `camelCase`, or `order[property]` | `itemsPerPage`, `order[createdAt]` |
| Doctrine entity + property | English `camelCase` (the framework maps the column) | `duration`, `updatedAt` |
| Twig template | `snake_case`, `_`-free unless it is a partial | `templates/admin/recipe/index.html.twig` |
| Stimulus controller file | `snake_case_controller.js` | `assets/controllers/` |

- **No French, no franglais, no abbreviations** in identifiers: not `$duree`, not `getRecettes()`,
  not `$categ`. Use `$duration`, `getRecipes()`, `$category`.
- **No transliterated accents** in code (`duree`, `quantite`) — translate the concept.
- Booleans read as predicates: `isPublished`, `hasIngredients`.
- Collections are plural (`$recipes`), a single item singular (`$recipe`).

**The codebase is clean on this point today** — there is no French-identifier drift in `src/`. Keep
it that way; a single `$duree` is the beginning of the drift.

## Public names are contracts

Three categories of name are consumed from outside this repository and **cannot be renamed
casually** — see [../business/cookbook.md](../business/cookbook.md):

- an exposed API property or query parameter (Radiant reads them);
- an MCP tool `name` (configured clients call them by name);
- a route path under `/api/v1`.

Renaming one is a breaking change: announce it, and use `feat!:` / `BREAKING CHANGE`.

## Comments — English, and only when they earn their place

Write every comment in **English**, whatever the syntax (`//`, `#`, `/* */`, `{# #}`). A comment
addresses a developer, so the French exception never applies to it.

Keep a comment only when it carries what the code cannot show:

- an external constraint — `OVH runs PHP as FastCGI: no php_flag in .htaccess`;
- a decision and the option it rejects — `bound parameter, not concatenation: the value is a raw
  query string`;
- a trap that costs a debugging session — `container env vars beat .env.test, so APP_ENV must be
  passed explicitly`.

Delete the rest. A comment that paraphrases the next line or narrates history (`this used to be…`)
is noise to maintain.

## French — the words users read

Unlike Radiant, **this project has a real translation catalogue**: `translations/messages.fr.yaml`
and `messages.en.yaml`. `default_locale` is `en`, and `App\EventListener\LocaleListener` picks the
request locale from the `Accept-Language` header, **defaulting to `fr`**.

- User-facing strings go through the catalogue and a translation **key** — the key is an English
  `snake_case` identifier (`ingredient_unit.tbsp`), the *value* is the translated text.
- Add every new key to **both** `messages.fr.yaml` and `messages.en.yaml`. A key present in only
  one file silently renders as the raw key for the other locale.
- Do not hardcode a French string in a template or a controller when the catalogue is right there.

## See also
- Backend conventions: [backend-php.md](backend-php.md)
- Domain vocabulary and the API contract: [../business/cookbook.md](../business/cookbook.md)
