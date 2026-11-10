#!/bin/sh
# Production boot for the FrankenPHP container, run on EVERY container start.
# Prepares the application (permissions, database, JWT keypair, cache, migrations)
# as root so each step can write, then binds :8080. See docs/deploy/coolify.md.
#
# The order matters: the database is reached before anything else, so a wrong
# DATABASE_URL fails the boot on the step that names it rather than halfway
# through a cache rebuild.
set -eu

APP_DIR=/app
VAR_DIR="${APP_DIR}/var"
UPLOAD_DIR="${APP_DIR}/public/images/recipes_thumbnails"
JWT_DIR="${APP_DIR}/config/jwt"

log() {
	printf '[entrypoint] %s\n' "$1"
}

ensure_directories() {
	mkdir -p "${VAR_DIR}/cache" "${VAR_DIR}/log" "${VAR_DIR}/sessions" \
		"${UPLOAD_DIR}" "${JWT_DIR}"
}

# The upload and JWT directories are Coolify volumes: they are mounted over the
# image's own, so their owner comes from the host and has to be fixed here rather
# than in the Dockerfile.
fix_permissions() {
	[ "${APP_FIX_PERMISSIONS:-1}" = "1" ] || return 0
	chown -R www-data:www-data "${VAR_DIR}" "${UPLOAD_DIR}" "${JWT_DIR}"
	chmod -R ug+rwX "${VAR_DIR}" "${JWT_DIR}"
}

# Creates the database and waits for it in one move: the container may boot before
# the database server accepts connections, and `database:create --if-not-exists` is
# the cheapest call that proves the server is reachable. Bounded, so a genuine
# misconfiguration still fails the boot instead of hanging forever.
DB_ATTEMPTS=30
DB_RETRY_SECONDS=2

wait_for_database() {
	attempt=1
	while [ "${attempt}" -le "${DB_ATTEMPTS}" ]; do
		if php bin/console doctrine:database:create --if-not-exists --env=prod --no-debug 2>/dev/null; then
			log "Database reachable"
			return 0
		fi

		log "Database unreachable (attempt ${attempt}/${DB_ATTEMPTS}) — retrying in ${DB_RETRY_SECONDS}s"
		attempt=$((attempt + 1))
		sleep "${DB_RETRY_SECONDS}"
	done

	log "Database still unreachable after ${DB_ATTEMPTS} attempts — check DATABASE_URL"
	return 1
}

# config/jwt is a persistent volume, so the keypair generated on the first boot
# survives every redeployment and this step is a no-op afterwards. Without the keys
# every POST /api/login_check fails, which is how Radiant authenticates.
# Regenerating them invalidates all issued tokens, hence --skip-if-exists.
ensure_jwt_keypair() {
	[ "${APP_GENERATE_JWT_KEYS:-1}" = "1" ] || return 0
	log "Ensuring the JWT keypair exists"
	php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction --env=prod --no-debug
	chown -R www-data:www-data "${JWT_DIR}"
}

run_cache_refresh() {
	[ "${APP_CLEAR_CACHE:-1}" = "1" ] || return 0
	log "Clearing Symfony cache"
	php bin/console cache:clear --env=prod --no-debug --no-warmup
	log "Warming Symfony cache"
	# --no-optional-warmers is load-bearing: it keeps DoctrineMetadataCacheWarmer out of
	# the run. A plain `cache:warmup --env=prod` aborts with "DoctrineMetadataCacheWarmer
	# must load metadata first, check priority of your warmers", which under `set -e`
	# fails the boot. The rest of the cache is built lazily on the first request.
	php bin/console cache:warmup --env=prod --no-debug --no-optional-warmers
}

# Idempotent, and the only place migrations run. Consequence to design for: every
# migration executes unattended on every container start, so it must be
# non-interactive and backward-safe.
run_migrations() {
	[ "${APP_RUN_MIGRATIONS:-1}" = "1" ] || return 0
	log "Running Doctrine migrations"
	php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration
	php bin/console doctrine:migrations:status --env=prod --no-debug
}

cd "${APP_DIR}"

ensure_directories
fix_permissions
wait_for_database
ensure_jwt_keypair
run_cache_refresh
run_migrations
fix_permissions

log "Starting FrankenPHP on :8080"
exec docker-php-entrypoint --config /etc/frankenphp/Caddyfile --adapter caddyfile
