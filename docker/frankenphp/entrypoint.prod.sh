#!/bin/sh
# Production boot for the FrankenPHP container, run on EVERY container start.
# Prepares the application (permissions, database, the two keypairs, cache, migrations)
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
OAUTH_DIR="${APP_DIR}/config/oauth"

log() {
	printf '[entrypoint] %s\n' "$1"
}

ensure_directories() {
	mkdir -p "${VAR_DIR}/cache" "${VAR_DIR}/log" "${VAR_DIR}/sessions" \
		"${UPLOAD_DIR}" "${JWT_DIR}" "${OAUTH_DIR}"
}

# The upload, JWT and OAuth directories are Coolify volumes: they are mounted over
# the image's own, so their owner comes from the host and has to be fixed here rather
# than in the Dockerfile.
#
# The OAuth keys are the one place the mask is NARROWED instead of widened. league's
# own file-mode check is switched off by the bundle (it builds its CryptKey with
# $keyPermissionsCheck = false), and `Filesystem::dumpFile` leaves the pair at 0664 —
# so nothing but this line keeps a private key from being world-readable inside the
# container. 0660 is what league itself recommends.
fix_permissions() {
	[ "${APP_FIX_PERMISSIONS:-1}" = "1" ] || return 0
	chown -R www-data:www-data "${VAR_DIR}" "${UPLOAD_DIR}" "${JWT_DIR}" "${OAUTH_DIR}"
	chmod -R ug+rwX "${VAR_DIR}" "${JWT_DIR}"
	chmod u+rwx,g+rwx,o-rwx "${OAUTH_DIR}"
	# Runs before the keypair exists on the very first boot, hence the test. The second
	# fix_permissions call at the end of the boot is the one that catches a fresh pair.
	for key in "${OAUTH_DIR}/private.pem" "${OAUTH_DIR}/public.pem"; do
		if [ -f "${key}" ]; then
			chmod 0660 "${key}"
		fi
	done
}

# Creates the database and waits for it in one move: the container may boot before
# the database server accepts connections, and `database:create --if-not-exists` is
# the cheapest call that proves the server is reachable. Bounded, so a genuine
# misconfiguration still fails the boot instead of hanging forever.
DB_ATTEMPTS=30
DB_RETRY_SECONDS=2

wait_for_database() {
	# The one step that can hard-fail the boot, so it needs an escape hatch like the others:
	# with APP_WAIT_FOR_DATABASE=0 the container comes up anyway, which is what makes it
	# possible to open a shell on it and diagnose the connection by hand.
	if [ "${APP_WAIT_FOR_DATABASE:-1}" != "1" ]; then
		log "Skipping the database wait (APP_WAIT_FOR_DATABASE=0)"
		return 0
	fi

	attempt=1
	last_error=''
	while [ "${attempt}" -le "${DB_ATTEMPTS}" ]; do
		if last_error=$(php bin/console doctrine:database:create --if-not-exists --env=prod --no-debug 2>&1); then
			log "Database reachable"
			return 0
		fi

		log "Database not ready (attempt ${attempt}/${DB_ATTEMPTS}) — retrying in ${DB_RETRY_SECONDS}s"
		attempt=$((attempt + 1))
		sleep "${DB_RETRY_SECONDS}"
	done

	# Print what the connection actually returned. Discarding it (the previous behaviour) made
	# every cause look identical: an unresolvable host, a refused password and a missing
	# privilege all surfaced as the same "unreachable" line and sent the reader after the
	# wrong thing.
	log "Database not ready after ${DB_ATTEMPTS} attempts. The last error follows; it is what"
	log "tells an unreachable host from a refused authentication or a missing privilege."
	printf '%s\n' "${last_error}" >&2
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

# The OAuth 2.1 authorization server's own keypair, in config/oauth — a different pair
# from the one above, with a different blast radius: regenerating this one invalidates
# every MCP access and refresh token, regenerating Lexik's logs out Radiant. Keeping
# them in separate directories is what lets either be redone without forcing the other.
#
# THIS STEP IS ONLY CORRECT BECAUSE /app/config/oauth IS A PERSISTENT VOLUME. Without
# one it writes into the image's own filesystem, which is rebuilt on every deploy, so
# every container start would hand out a fresh keypair and invalidate every token ever
# issued. --skip-if-exists is what makes the second boot a no-op; the volume is what
# makes the keys still be there to skip.
#
# OAUTH_PASSPHRASE is baked into the private key here, at generation time, by
# openssl_pkey_export(). So this step REFUSES to create a pair while the variable is
# empty: a key born without a passphrase is unencrypted on the persistent volume for
# as long as it lives, and nothing ever reports it — OpenSSL ignores a passphrase
# handed to an unencrypted key, so the server keeps working. The cost only lands much
# later, as league's "Unable to read key from file" pointing into vendor/.
#
# It skips rather than failing the boot, unlike the database step. Halting here would
# take the whole API down — Radiant included — for a subsystem nothing consumes before
# phase 5. The API stays up, only /oauth2/* cannot issue a token, and the first boot
# after the variable is set creates the pair correctly with nothing to repair.
ensure_oauth_keypair() {
	[ "${APP_GENERATE_OAUTH_KEYS:-1}" = "1" ] || return 0

	if [ ! -f "${OAUTH_DIR}/private.pem" ] && [ -z "${OAUTH_PASSPHRASE:-}" ]; then
		log "NOT generating the OAuth keypair: OAUTH_PASSPHRASE is empty."
		log "  Generating one now would write an UNENCRYPTED private key to the"
		log "  config/oauth volume, and nothing afterwards would report it."
		log "  Set OAUTH_PASSPHRASE in the environment and restart: the next boot"
		log "  creates the pair with it. Until then /oauth2/* cannot issue a token;"
		log "  the rest of the API is unaffected."
		return 0
	fi

	log "Ensuring the OAuth keypair exists"
	php bin/console league:oauth2-server:generate-keypair --skip-if-exists --no-interaction --env=prod --no-debug
	chown -R www-data:www-data "${OAUTH_DIR}"

	# An existing pair is never regenerated here — that is a decision, not a boot side
	# effect — so an unprotected one can only be reported, not repaired. This is the
	# case the guard above cannot prevent: a key already born on a volume before the
	# variable existed. Worth a line on every boot precisely because it is silent.
	if [ -n "${OAUTH_PASSPHRASE:-}" ] && ! grep -q 'ENCRYPTED' "${OAUTH_DIR}/private.pem" 2>/dev/null; then
		log "WARNING: config/oauth/private.pem is NOT encrypted, though OAUTH_PASSPHRASE is set."
		log "  It was generated while the variable was empty. Tokens still work, which is"
		log "  exactly why this goes unnoticed. The fix regenerates the pair and invalidates"
		log "  every issued token — see docs/deploy/coolify.md."
	fi
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
ensure_oauth_keypair
run_cache_refresh
run_migrations
fix_permissions

log "Starting FrankenPHP on :8080"
exec docker-php-entrypoint --config /etc/frankenphp/Caddyfile --adapter caddyfile
