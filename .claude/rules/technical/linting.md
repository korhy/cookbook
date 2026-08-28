---
description: The pre-CI quality gate — php-cs-fixer (@Symfony) and PHPStan level 5.
paths:
  - "**/*.php"
  - "**/.php-cs-fixer.dist.php"
  - "**/phpstan.dist.neon"
  - "**/.github/workflows/*.yml"
---

# Linting / static analysis

**A change is not done until the linters pass.** CI runs exactly two gates, and both must be green
before a commit is proposed:

```bash
make lint              # php-cs-fixer (dry-run) + phpstan
make php-cs-fixer-fix  # autofix the style findings
```

Both run **inside the container** — `make up` first.

## php-cs-fixer

`@Symfony` ruleset, configured in `.php-cs-fixer.dist.php`. It walks the whole project except
`var/`, `migrations/`, `vendor/`, and three specific files: `config/bundles.php`,
`config/reference.php`, `public/adminer.php`.

`config/reference.php` is excluded for a reason worth knowing: it is **rewritten on the production
server**, and `deploy.yml` runs `git checkout -- config/reference.php` before pulling. Never
reformat it, never commit a change to it.

## PHPStan

Level **5**, over `bin/`, `config/`, `public/`, `src/` and `tests/` (`phpstan.dist.neon`).

- `property.unusedType` is globally ignored — that is the Doctrine nullable-property idiom, not a
  finding.
- **Do not add an `ignoreErrors` entry to get green.** Fix the type, or narrow the ignore to a
  single identifier and say in the commit why. A baseline file is not the answer either.
- The Make target passes `-d memory_limit=-1`: PHPStan's parallel workers otherwise hit the
  container's cap and die with exit code 255, which says nothing about the code.
- PHPDoc array shapes are load-bearing at level 5 — that is why the MCP tools carry
  `@return array{recipes: array<int, array{…}>}`. Keep writing them.

## What is *not* gated

There is **no Twig linter and no JS/CSS linter** in this project (unlike gestion-bachelor and
Radiant, which run twig-cs-fixer). The back-office templates and the AssetMapper JavaScript have no
automated style gate — review them by hand and load the page.

## Order of operations

1. `make php-cs-fixer-fix` — mechanical style.
2. `make phpstan` — types.
3. `make db-test && make phpunit` — behaviour.
4. Only then propose a commit.
