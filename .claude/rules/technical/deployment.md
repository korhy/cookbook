---
description: Deployment and CI mechanics — the FrankenPHP production image, the GHCR build, Coolify, and migrations that run unattended at container boot.
paths:
  - "**/migrations/**"
  - "**/.github/workflows/**"
  - "**/docker/**"
  - "**/compose*.yaml"
  - "**/.dockerignore"
---

# Deployment & CI

Production runs on **Coolify** (VPS), pulling an image that **CI builds**. One workflow:
`.github/workflows/ci.yml`. The full resource configuration is in
[docs/deploy/coolify.md](../../../docs/deploy/coolify.md).

**There is no SSH deployment and no `deploy.yml`.** Nothing is built on the server, nothing is
pulled with git there, and no command is typed on the VPS to finish a deploy.

## CI (`ci.yml`)

Two jobs, on push/PR to `main`:

| Job | What it does |
|---|---|
| `ci` | php-cs-fixer `--dry-run`, PHPStan level 5, PHPUnit on PostgreSQL 16 — see [linting.md](linting.md) |
| `build-images` | Builds `docker/frankenphp/Dockerfile.prod` and pushes it to GHCR |

`build-images` runs **only** on push to `main` and **only** once `ci` is green, so a broken commit
never produces a new `latest`:

```
ghcr.io/korhy/cookbook/frankenphp:latest
ghcr.io/korhy/cookbook/frankenphp:<commit-sha>
```

It authenticates with the automatic `GITHUB_TOKEN` (`packages: write`) — no PAT in CI. **Coolify
pulls this image; it does not build it.** GHCR does not notify Coolify, so shipping is a *Redeploy*
click (or a webhook, not wired yet).

## Docker — FrankenPHP (PHP 8.4 + Caddy)

Everything is under `docker/frankenphp/`. No nginx, no php-fpm, no Supervisor: FrankenPHP bundles
Caddy and PHP in a single process. WORKDIR is **`/app`**.

| File | Role |
|---|---|
| `Dockerfile` | Dev image, used by `compose.yaml`. Floating `php8.4` tag, bind-mounted source, `dev-entrypoint.sh` |
| `Dockerfile.prod` | Production image: pinned `php8.4.27`, `COPY . .`, composer `--no-dev`, the three asset commands. Built by CI |
| `Caddyfile` / `Caddyfile.prod` | Caddy config: dev `:80`, prod `:8080` with a `/healthz` route |
| `entrypoint.prod.sh` | The production boot sequence (below) |

The two images keep the **same extension list** (`pdo_pgsql intl zip opcache mbstring gd`) on
purpose — they diverge only where dev and prod genuinely differ (OPcache revalidation, the PHP
version pin, `error_log=/dev/stderr`).

### Assets are built into the image

AssetMapper, so there is no Node stage and no `npm`. `Dockerfile.prod` runs all three commands,
and **all three are required** because each writes to a gitignored directory absent from a fresh
checkout:

```
assets:install public   → public/bundles/   (EasyAdmin's published CSS/JS)
importmap:install       → assets/vendor/    (third-party JS)
asset-map:compile       → public/assets/    (the compiled, versioned map)
```

Dropping `assets:install` ships a back-office with no CSS. Never commit `public/assets/`,
`assets/vendor/` or `public/bundles/`.

### Production boot sequence (`entrypoint.prod.sh`)

Runs on **every** container start, before binding `:8080`:

1. Create directories, then `chown` `var/`, the thumbnails volume and `config/jwt` — the volumes
   are mounted over the image's own, so their owner comes from the host.
2. `doctrine:database:create --if-not-exists`, retried 30× at 2 s — doubles as the wait for the
   database to accept connections. Bounded, so a wrong `DATABASE_URL` fails the boot.
3. `lexik:jwt:generate-keypair --skip-if-exists` — the keypair is born on the first boot inside a
   persistent volume and reused thereafter.
4. `cache:clear --no-warmup`, then `cache:warmup --no-optional-warmers`.
5. `doctrine:migrations:migrate --no-interaction --allow-no-migration`, then `migrations:status`.

Each step has an off switch (`APP_FIX_PERMISSIONS`, `APP_GENERATE_JWT_KEYS`, `APP_CLEAR_CACHE`,
`APP_RUN_MIGRATIONS`), all defaulting to on.

**`--no-optional-warmers` is load-bearing.** A plain `cache:warmup --env=prod` aborts with
*"DoctrineMetadataCacheWarmer must load metadata first, check priority of your warmers"* — verified
inside this container. Under `set -e` that fails the boot. The rest of the cache is built lazily on first request.

## What you must design for

1. **Every migration runs unattended, on every container (re)start, right after the merge.** It
   must be non-interactive and **backward-safe**. A destructive `DROP COLUMN` shipped in the same
   release as the code that stops using it breaks requests in flight — split it across two
   releases. Read the generated SQL before committing it.
2. **A green deploy does not prove the migration ran.** Check the `migrations:status` the entrypoint
   prints: `New` must be **0**.
3. **Uploads and JWT keys live on persistent volumes**, not in the image:
   `/app/public/images/recipes_thumbnails` (Vich) and `/app/config/jwt` (Lexik). A container is
   rebuilt on every deploy; without the volumes a redeploy wipes them. Never write code that
   assumes a clean rebuild.
4. **Never drop a file into the thumbnails directory by hand** (terminal, `docker cp`, Coolify's
   file browser) to "fix" a missing upload. Vich stores the **exact filename** in
   `Recipe::$thumbnail`; a file on disk under any other name is invisible and 404s. Upload through
   EasyAdmin, which keeps the filename and the row in sync.
5. **`var/` is not persisted.** Admin sessions, MCP HTTP sessions and the three MCP rate-limiter
   windows (`cache.rate_limiter` lives in `var/cache`) all reset on redeploy. Accepted — but never
   put anything in `var/` that must survive.
6. **Secrets are Coolify runtime environment variables**, never inlined in the image or a workflow.
   `TRUSTED_PROXIES=private_ranges` is mandatory, not cosmetic: see
   [security.md](security.md).

## Conventional Commits drive the version

`VERSION` and the release tagging read the commit **subject**: `feat:` → minor, `fix:` → patch,
`type!:` or `BREAKING CHANGE:` → major, anything else → no release. A feature committed as `chore:`
ships untagged. Write the subject deliberately.
