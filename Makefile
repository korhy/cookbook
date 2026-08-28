## Cookbook — dev tasks. Run `make help` for the list.

DOCKER_COMPOSE = docker compose

# `docker compose exec` aborts with "the input device is not a TTY" when stdin isn't a terminal
# (scripts, CI, an editor's task runner). Detect it and pass -T in that case only, so interactive
# runs keep their colours and a piped run still works.
TTY := $(shell test -t 0 && echo 1)
EXEC = $(DOCKER_COMPOSE) exec $(if $(TTY),,-T)

APP = $(EXEC) app
CONSOLE = $(APP) php bin/console
COMPOSER = $(APP) composer
PHP_CS_FIXER = $(APP) vendor/bin/php-cs-fixer
# PHPStan's parallel workers can exhaust the container's memory cap and die with exit code 255,
# which says nothing about the code. CI has no such cap, hence the flag lives here.
PHPSTAN = $(APP) php -d memory_limit=-1 vendor/bin/phpstan

# The container exports APP_ENV=dev and a dev DATABASE_URL as *real* environment variables, and
# those win over .env.test. Without these -e flags the test kernel boots in dev and the suite
# runs against the development database. CI sets them explicitly for the same reason.
#
# The dbname stays `app`: config/packages/doctrine.yaml appends `dbname_suffix: _test` in the test
# environment, so this resolves to `app_test`. Naming it `app_test` here would give `app_test_test`.
#
# JWT_PASSPHRASE is deliberately NOT overridden: the keypair in config/jwt/ was generated with the
# passphrase from .env.local, and forcing another value fails every API test with an opaque
# "error while trying to encode the JWT token". CI can pin it because it generates fresh keys.
TEST_DATABASE_URL = postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8
PHPUNIT = $(EXEC) -e APP_ENV=test -e DATABASE_URL='$(TEST_DATABASE_URL)' app php bin/phpunit
TEST_CONSOLE = $(EXEC) -e APP_ENV=test -e DATABASE_URL='$(TEST_DATABASE_URL)' app php bin/console

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

## —— Docker (local dev) ————————————————————————————————————————————
.PHONY: net
net: ## Create the shared korhy_net network if missing (idempotent)
	@docker network inspect korhy_net >/dev/null 2>&1 || docker network create korhy_net

.PHONY: up
up: net ## Start the dev stack (API :8001, Postgres :5435, Mailpit :8025, Adminer :8083)
	$(DOCKER_COMPOSE) up -d --build

.PHONY: down
down: ## Stop the dev stack
	$(DOCKER_COMPOSE) down

.PHONY: restart
restart: ## Recreate the app container (picks up compose.yaml changes)
	$(DOCKER_COMPOSE) up -d --force-recreate app

.PHONY: ps
ps: ## Show the stack status and published ports
	$(DOCKER_COMPOSE) ps

.PHONY: logs
logs: ## Tail the dev stack logs
	$(DOCKER_COMPOSE) logs -f

.PHONY: sh
sh: ## Open a shell in the app container
	$(DOCKER_COMPOSE) exec app sh

## —— Symfony ———————————————————————————————————————————————————————
.PHONY: install
install: ## Install PHP dependencies
	$(COMPOSER) install

.PHONY: cc
cc: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

.PHONY: console
console: ## Run a console command (make console C="debug:router")
	$(CONSOLE) $(C)

## —— Database ——————————————————————————————————————————————————————
.PHONY: db-migration
db-migration: ## Generate a migration from the mapping (review the SQL!)
	$(CONSOLE) make:migration

.PHONY: db-migrate
db-migrate: ## Apply pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

.PHONY: db-validate
db-validate: ## Check the mapping matches the database schema
	$(CONSOLE) doctrine:schema:validate

.PHONY: db-test
db-test: ## Create the app_test database and migrate it (run before `make phpunit`)
	$(TEST_CONSOLE) doctrine:database:create --if-not-exists --no-interaction
	$(TEST_CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

.PHONY: psql
psql: ## Open a psql shell on the dev database
	$(EXEC) database psql -U $${POSTGRES_USER:-app} -d $${POSTGRES_DB:-app}

## —— Authentication ————————————————————————————————————————————————
.PHONY: jwt-keys
jwt-keys: ## Generate the Lexik JWT keypair if missing (the entrypoint does this too)
	$(CONSOLE) lexik:jwt:generate-keypair --skip-if-exists --no-interaction

.PHONY: admin
admin: ## Print a password hash to insert into the admin table (see README)
	$(CONSOLE) security:hash-password

## —— Data ——————————————————————————————————————————————————————————
.PHONY: import-csv
import-csv: ## Import the CSV files from public/data (make import-csv ARGS="--dry-run")
	$(CONSOLE) app:import-csv --skip-header --batch-size=50 $(ARGS)

## —— Assets (AssetMapper — no npm, no build step) ———————————————————
.PHONY: assets
assets: ## Install vendor assets and compile the asset map
	$(CONSOLE) importmap:install
	$(CONSOLE) asset-map:compile

## —— Linting / static analysis —————————————————————————————————————
.PHONY: php-cs-fixer
php-cs-fixer: ## Check PHP code style (@Symfony)
	$(PHP_CS_FIXER) fix --dry-run --diff

.PHONY: php-cs-fixer-fix
php-cs-fixer-fix: ## Fix PHP code style
	$(PHP_CS_FIXER) fix

.PHONY: phpstan
phpstan: ## Run static analysis (level 5)
	$(PHPSTAN) analyse

.PHONY: lint
lint: php-cs-fixer phpstan ## Run every linter

## —— Tests —————————————————————————————————————————————————————————
.PHONY: phpunit
phpunit: ## Run the test suite (use TEST=path for a subset) — run `make db-test` first
	$(PHPUNIT) $(if $(TEST),$(TEST),)

.PHONY: ci
ci: php-cs-fixer phpstan db-test phpunit ## Run exactly what .github/workflows/ci.yml runs
