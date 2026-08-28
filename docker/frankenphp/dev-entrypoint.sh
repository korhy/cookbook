#!/bin/sh
# Development bootstrap for the FrankenPHP container.
# Makes `make up` produce a ready-to-use API: install dependencies, wait for the
# database, generate the JWT keypair, apply migrations, then start the server.
set -e

cd /app

DB_HOST="${DB_HOST:-database}"
DB_PORT="${DB_PORT:-5432}"

echo "[dev] APP_ENV=${APP_ENV:-dev}"

# 1. Composer dependencies (skip if the bind-mounted vendor/ is already there).
if [ ! -f vendor/autoload_runtime.php ]; then
	echo "[dev] Installing Composer dependencies ..."
	composer install --no-interaction --prefer-dist
fi

# 2. Wait for the database to accept connections.
echo "[dev] Waiting for database at ${DB_HOST}:${DB_PORT} ..."
until php -r 'exit(@fsockopen(getenv("DB_HOST") ?: "database", (int) (getenv("DB_PORT") ?: 5432)) ? 0 : 1);' 2>/dev/null; do
	sleep 2
done
echo "[dev] Database is up."

# 3. JWT keypair. config/jwt/*.pem is git-ignored but the project root is bind-mounted,
# so the keys generated here persist on the host and this step is a no-op on later boots.
# Without them every POST /api/login_check fails.
echo "[dev] Ensuring the JWT keypair exists ..."
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction

# 4. Ensure the database exists.
php bin/console doctrine:database:create --if-not-exists --no-interaction || true

# Migrations are NOT applied on every boot in dev. Default (auto) only bootstraps
# a brand-new/empty database; on an existing schema you stay in control via
# `make db-migrate`. Override with RUN_MIGRATIONS=1 (always) or =0 (never).
case "${RUN_MIGRATIONS:-auto}" in
	1)
		echo "[dev] Applying migrations (RUN_MIGRATIONS=1) ..."
		php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true
		;;
	auto)
		if php bin/console dbal:run-sql "SELECT 1 FROM doctrine_migration_versions LIMIT 1" >/dev/null 2>&1; then
			echo "[dev] Existing schema — not migrating. Run 'make db-migrate' to apply pending migrations."
		else
			echo "[dev] Fresh database — bootstrapping the schema once ..."
			php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true
		fi
		;;
	*)
		echo "[dev] Skipping migrations (RUN_MIGRATIONS=${RUN_MIGRATIONS})."
		;;
esac

echo "[dev] Starting FrankenPHP on :80 ..."
exec frankenphp run --config /etc/frankenphp/Caddyfile
