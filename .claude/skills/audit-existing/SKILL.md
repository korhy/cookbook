---
name: audit-existing
description: Audit the existing Cookbook codebase against the project standards (strict typing, English naming, API contract drift, MCP read-only contract, security, dead code, test coverage, stale docs) and produce a prioritized report of what to redo vs keep. Read-only — proposes work, does not implement. Use when the user asks to audit the code, find what doesn't follow the standards, or "/audit-existing".
---

# /audit-existing — Standards audit of the existing code

Produce a prioritized, evidence-based report of where the current code diverges from this project's
standards, and what to redo vs keep. **This skill implements nothing** — it inventories and
recommends; the work is then done by `/new-api-resource`, `/new-mcp-tool`, or by hand.

## Constitution = the standards to audit against

`CLAUDE.md` + `.claude/rules/**`. The audit dimensions, in the order they matter for this project:

1. **API contract drift** ([api-platform.md](../../rules/technical/api-platform.md),
   [cookbook.md](../../rules/business/cookbook.md)) — **the highest-stakes dimension, because
   Radiant consumes this API.** Look for: entity properties with no `#[Groups]` that consumers
   probably expect; properties in a `read` group that should not be public; a documented filter
   whose `getDescription()` is missing or wrong, so the OpenAPI output lies; write operations that
   crept in; a collection operation without a pagination cap.
2. **MCP contract** ([mcp.md](../../rules/technical/mcp.md)) — the endpoint is **public and
   unauthenticated**. Check every tool in `src/Mcp/Tool/` for: any write (persist/flush/remove), an
   unbounded result set, a field not exposed by the REST API, an argument reaching a query
   unparameterised, a `description` that does not state the tool's limits.
3. **Typing** ([backend-php.md](../../rules/technical/backend-php.md)) — **no file in `src/` carries
   `declare(strict_types=1)` today** (39 files). Do not report that as 39 findings: report it once,
   with the list, and propose a file-by-file order weighted by risk (services and filters first,
   entities last). Also: untyped params/returns, classes that should be `final`.
4. **Security** ([security.md](../../rules/technical/security.md)) — the `access_control` ordering
   (only the first match applies, and `^/api` sits above nothing but the three `PUBLIC_ACCESS`
   lines); any new `/api/…` route that inherited `ROLE_ADMIN` when it should not have, or the
   reverse; raw role-string comparisons instead of `#[IsGranted]`; unbound parameters in
   `src/Filter/`; anywhere the JWT, the passphrase or the admin credentials could reach a log, an
   exception message or a template; `CORS_ALLOW_ORIGIN` widened past what dev needs.
5. **Backend conventions** — Doctrine queries outside `src/Repository/` (`grep -rn
   'createQueryBuilder\|getRepository' src --include='*.php'` then discount the repositories
   themselves); business logic in a controller or an EasyAdmin CRUD controller; fat actions.
6. **Naming** ([naming.md](../../rules/technical/naming.md)) — French or franglais identifiers,
   transliterated accents, abbreviations. The codebase was clean on this at the last check; the
   audit's job is to confirm it still is. Also check the translation catalogue: a key present in
   `messages.fr.yaml` but missing from `messages.en.yaml` (or vice versa) renders as the raw key.
7. **Dead code** — unused entities, repository methods nobody calls, Stimulus controllers in
   `assets/controllers/` never registered, importmap entries for packages no longer imported,
   `public/adminer.php` (superseded by the Adminer container on `:8083`), leftover dumps and PDFs
   at the repository root. Say what is safe to delete versus what is merely unused.
8. **Duplication** — repeated entity-to-array mapping across MCP tools that should be a service;
   repeated query fragments that belong in one repository method.
9. **Tests** ([testing.md](../../rules/technical/testing.md)) — map the existing suite
   (`tests/Api`, `tests/Mcp`, `tests/Repository`, `tests/Entity`, `tests/Service`,
   `tests/Controller`) against the risky surface. Name the **highest-value missing tests**
   specifically — an untested filter, an untested MCP tool, an unasserted response shape — rather
   than reporting a coverage percentage.
10. **Stale documentation** — `README.md` and `CLAUDE.md` against reality: URLs, ports, the routes
    and filters actually registered (`make console C="debug:router"`), the entity list, `DEPLOY.md`
    against `.github/workflows/deploy.yml`.

## Method (read-only)

1. **Inventory** the surface: `src/**`, `config/packages/**`, `config/routes/**`, `templates/**`,
   `assets/**`, `migrations/`, `tests/**`, `.github/workflows/`.
2. For each dimension, scan and record **file:line evidence** — never a vague claim. Useful signals:
   - `grep -rL 'declare(strict_types=1)' src --include='*.php'`
   - `grep -rn 'createQueryBuilder\|getRepository\|->persist(\|->flush()' src/Mcp src/Controller`
   - `grep -rn 'Groups' src/Entity` — cross-read against what Radiant actually consumes
   - `grep -rn 'setParameter' src/Filter` — and flag any string interpolation near a `where`
   - `make console C="debug:router"` for the real route list
   - `diff <(grep -oE '^[a-z_]+:' translations/messages.fr.yaml) <(grep -oE '^[a-z_]+:' translations/messages.en.yaml)`
3. **Cross-check against the consumer.** Before calling an API field unused, look at
   `radiant/src/Service/Cookbook/CookbookApiService.php` and
   `radiant/templates/**` — Radiant is a separate repository and nothing in this one will tell you
   what it reads.
4. **Classify** each finding: `redo` / `keep` / `investigate`, with **effort** (S/M/L) and **impact**
   (consumer-facing / security / risk / cosmetic).
5. **Fix nothing.** If a quick win is obvious, note it as a recommendation.

## Output — `docs/audit/audit-<date>.md`

Write a committed report (create `docs/audit/` if missing):

- **Summary** — counts per dimension, top risks, top quick wins.
- **Findings table** — `# | dimension | file:line | issue | classification | effort | next step`
  (→ `/new-api-resource`, `/new-mcp-tool`, or manual). Most impactful first.
- **Consumer-impact section** — call out separately anything whose fix would be a **breaking change
  for Radiant**, so the sequencing accounts for coordinating the two repositories.
- **Suggested order** — unblockers first.
- **Explicit "keep" list** — including the deliberate oddities, so nobody "fixes" them later:
  the read-only API (it is a design choice, not an omission), the `>=8.2` floor in `composer.json`
  while everything runs 8.4, and `config/reference.php` being excluded from php-cs-fixer because the
  production server rewrites it.

## Report

Summarize the biggest divergences, the recommended sequencing, and which skill handles each cluster.
Offer to start the highest-value slice — but only on request.
