SHELL := /bin/sh

# PHP CLI & Composer
PHP ?= php
COMPOSER ?= composer
GO ?= go

# Directories & admin keys. Toute la config réseau/URL vient de .env (SOFT_SERVE_*).
DATA_DIR ?= .data
SOFT_SERVE_INITIAL_ADMIN_KEYS ?= $(shell cat ~/.ssh/id_ed25519.pub 2>/dev/null || cat ~/.ssh/id_rsa.pub 2>/dev/null || echo "")

# Port SSH utilisé uniquement par `make publish` (tunnel bore). Le serveur lui-même
# lit SOFT_SERVE_SSH_LISTEN_ADDR depuis .env.
SSH_PORT ?= 2223

# Seule surcharge runtime : les clés admin détectées sur la machine (absentes de .env versionné).
SERVE_ENV = SOFT_SERVE_INITIAL_ADMIN_KEYS="$(SOFT_SERVE_INITIAL_ADMIN_KEYS)"

# Tunneling / Publish ports
PUBLISH_PORTS ?= 2220 2221 2222 2223 2224 2225 2226 2227 2228 2229

# Docker multi-container deployment (independent from `make run`).
DOCKER_COMPOSE ?= docker compose
DOCKER_ENV_FILE ?= .env.docker
DC = $(DOCKER_COMPOSE) --env-file $(DOCKER_ENV_FILE)

# Upstream synchronization
UPSTREAM_URL ?= https://github.com/charmbracelet/soft-serve.git
UPSTREAM_REMOTE ?= upstream
UPSTREAM_BRANCH ?= main

# Preflight tooling checks (run before `make run` / `make debug`)
REQUIRED_BINS ?= $(PHP) $(COMPOSER) git
OPTIONAL_BINS ?= git-lfs gpg bore
REQUIRED_PHP_EXTS ?= ffi mbstring pcntl posix

.PHONY: all build setup preflight install run serve debug e2e browse publish migrate rollback sync-hooks test test-render lint cs cs-check stan deptrac clean clean-all help upstream-setup upstream-fetch upstream-status upstream-sync docker-env docker-build docker-up docker-watch docker-down docker-logs docker-ps docker-migrate docker-rollback docker-import-sqlite docker-import-repos docker-import

all: build

## build: Build bin/gickers-ssh and make CLI entry points executable (bin/soft, bin/console)
build:
	@chmod +x bin/soft bin/console
	cd cmd/gickers-ssh && $(GO) build -o ../../bin/gickers-ssh .
	@ln -sf gickers-ssh bin/codes-ssh

## setup: Install / check system tools (php, composer, git, go >= 1.22)
setup:
	@echo "==> Verification des outils d'environnement..."
	@which $(PHP) >/dev/null 2>&1 || { echo "!! php introuvable"; exit 1; }
	@which $(COMPOSER) >/dev/null 2>&1 || { echo "!! composer introuvable"; exit 1; }
	@which git >/dev/null 2>&1 || { echo "!! git introuvable"; exit 1; }
	@which $(GO) >/dev/null 2>&1 || { echo "!! go (>= 1.22) introuvable"; exit 1; }
	@go_ver=$$($(GO) version | sed -n 's/.*go\([0-9]*\.[0-9]*\).*/\1/p'); \
		go_major=$$(echo "$$go_ver" | cut -d. -f1); go_minor=$$(echo "$$go_ver" | cut -d. -f2); \
		if [ "$${go_major:-0}" -lt 1 ] || { [ "$${go_major:-0}" -eq 1 ] && [ "$${go_minor:-0}" -lt 22 ]; }; then \
			echo "!! go >= 1.22 requis (trouve: go$$go_ver)"; exit 1; \
		fi
	@echo "==> Verification de bore (tunneling public gickers.sh)..."
	@if ! which bore >/dev/null 2>&1; then \
		if which brew >/dev/null 2>&1; then \
			echo "Installation de bore via brew..."; \
			brew install bore-cli; \
		else \
			echo "!! bore introuvable. Installez-le manuellement: brew install bore-cli ou cargo install bore-cli"; \
		fi; \
	else \
		echo "bore est deja installe ($$(which bore))"; \
	fi

## install: Install PHP composer dependencies
install:
	$(COMPOSER) install

## preflight: Verify required tools, PHP extensions, deps and migrations before running
preflight: build
	@echo "==> Preflight gickers.sh..."
	@missing=""; \
	for bin in $(REQUIRED_BINS); do \
		command -v $$bin >/dev/null 2>&1 || missing="$$missing $$bin"; \
	done; \
	if [ -n "$$missing" ]; then \
		echo "!! outils requis introuvables:$$missing"; \
		echo "   installez-les puis relancez."; exit 1; \
	fi; \
	echo "   outils requis OK ($(REQUIRED_BINS))"
	@test -x bin/gickers-ssh || test -x bin/codes-ssh || { echo "!! bin/gickers-ssh introuvable (lancez make build)"; exit 1; }
	@for bin in $(OPTIONAL_BINS); do \
		command -v $$bin >/dev/null 2>&1 || echo "   (optionnel) $$bin absent"; \
	done
	@missing_ext=""; \
	for ext in $(REQUIRED_PHP_EXTS); do \
		$(PHP) -m 2>/dev/null | grep -qi "^$$ext$$" || missing_ext="$$missing_ext $$ext"; \
	done; \
	if [ -n "$$missing_ext" ]; then \
		echo "!! extensions PHP manquantes:$$missing_ext"; exit 1; \
	fi; \
	echo "   extensions PHP OK ($(REQUIRED_PHP_EXTS))"
	@if [ ! -f vendor/autoload.php ]; then \
		echo "==> dependances composer absentes, installation..."; \
		$(COMPOSER) install --no-interaction; \
	else \
		echo "   dependances composer OK"; \
	fi
	@echo "==> Migrations (idempotent, sans effacement des donnees)..."
	@./bin/soft doctrine:migrations:migrate --allow-no-migration --no-interaction

## run: Run gickers.sh (gickers-ssh :2223, HTTP :23232, Messenger worker)
run: preflight
	$(SERVE_ENV) ./bin/soft serve

## serve: Alias of run
serve: run

## debug: Run the server with verbose output (process command lines, debug logs)
debug: preflight
	$(SERVE_ENV) LOG_LEVEL=debug ./bin/soft serve -vv

## e2e: End-to-end run on throwaway ports/data: ssh signup, repo create, git push over SSH, clone over HTTP
e2e: build
	./tests/e2e/run.sh

## browse: Launch the gickers.sh terminal UI repository browser
browse: build
	./bin/soft browse

## docker-env: Create .env.docker from the example if missing
docker-env:
	@test -f $(DOCKER_ENV_FILE) || { cp .env.docker.example $(DOCKER_ENV_FILE); echo ">> $(DOCKER_ENV_FILE) créé depuis .env.docker.example — édite APP_SECRET et SOFT_SERVE_INITIAL_ADMIN_KEYS"; }

## docker-build: Build the shared container image (Go gateway + PHP app)
docker-build: docker-env
	$(DC) build init deploy-dockerd

## docker-up: Start the full stack (postgres, init, http, ssh, worker) in the background
docker-up: docker-env
	$(DC) build init deploy-dockerd
	$(DC) up -d --no-build --remove-orphans
	@set -a; . ./$(DOCKER_ENV_FILE); set +a; \
		echo ">> HTTP: $${SOFT_SERVE_HTTP_PUBLIC_URL:-http://localhost:$${HTTP_PORT:-80}} (host port $${HTTP_PORT:-80})"; \
		echo ">> SSH:  $${SOFT_SERVE_SSH_PUBLIC_URL:-ssh://localhost:$${SSH_PORT:-2223}} (host port $${SSH_PORT:-2223})"

## docker-watch: Start stack and follow with docker compose watch for hot reload
docker-watch: docker-env
	@pkill -f "docker.*compose.*watch" 2>/dev/null || true
	$(DC) build init deploy-dockerd
	$(DC) up -d --no-build --remove-orphans
	$(DC) watch --no-up

## docker-down: Stop the stack (add ARGS=-v to also drop volumes/data)
docker-down:
	@pkill -f "docker.*compose.*watch" 2>/dev/null || true
	$(DC) down --remove-orphans $(ARGS)

## docker-logs: Follow logs (optionally SERVICE=http|ssh|worker|init|postgres)
docker-logs:
	$(DC) logs -f $(SERVICE)

## docker-ps: Show the status of the stack services
docker-ps:
	$(DC) ps

## docker-migrate: Apply database migrations in Docker (PostgreSQL; optional ARGS="...")
docker-migrate: docker-env
	$(DC) run --rm init admin migrate $(ARGS)

## docker-rollback: Rollback database to the previous migration step in Docker (optional ARGS="...")
docker-rollback: docker-env
	$(DC) run --rm init admin rollback $(ARGS)

## docker-import-sqlite: Replace Postgres data with a legacy SQLite db (stack must be up; SRC=path, default .data/gickers-sh.db)
docker-import-sqlite:
	@SRC_FILE=$$(test -f "$(SRC)" && echo "$(SRC)" || (test -f .data/gickers-sh.db && echo .data/gickers-sh.db || echo .data/codes-sh.db)); \
	test -f "$$SRC_FILE" || { echo "!! fichier SQLite introuvable: $$SRC_FILE"; exit 1; }
	$(DC) run --rm --no-deps \
		-v "$(abspath $(or $(SRC),.data/gickers-sh.db)):/tmp/import.db:ro" \
		worker admin db:import-sqlite /tmp/import.db --truncate

## docker-import-repos: Copy on-disk bare repositories + LFS into the volume and resync hooks (stack must be up)
docker-import-repos:
	@test -d $(DATA_DIR)/repos || { echo "!! $(DATA_DIR)/repos introuvable"; exit 1; }
	$(DC) run --rm --no-deps \
		-v "$(abspath $(DATA_DIR)/repos):/seed/repos:ro" \
		-v "$(abspath $(DATA_DIR)/lfs):/seed/lfs:ro" \
		--entrypoint sh worker -c 'cp -a /seed/repos/. /app/.data/repos/ && cp -a /seed/lfs/. /app/.data/lfs/ 2>/dev/null; php bin/soft admin sync-hooks'

## install: Run the one-shot installer & deployment wizard (local or VPS)
install:
	@php bin/console install $(ARGS)

## deploy-vps: Run the automated VPS production deployment script (delegates to bin/console install)
deploy-vps:
	@./scripts/deploy-vps.sh $(ARGS)

## docker-import: Full migration into the running stack — SQLite data then on-disk repositories
docker-import: docker-import-sqlite docker-import-repos

## publish: Run soft serve and expose it publicly via bore on a free port (2220-2229)
publish: build
	@command -v bore >/dev/null 2>&1 || { echo "!! bore introuvable: brew install bore-cli"; exit 1; }
	@BORE_PORT=""; BORE_PID=""; \
	for p in $(PUBLISH_PORTS); do \
		echo ">> tentative bore.pub:$$p ..."; \
		bore local $(SSH_PORT) --to bore.pub --port $$p >/tmp/bore-publish.log 2>&1 & \
		bpid=$$!; \
		sleep 3; \
		if kill -0 $$bpid 2>/dev/null; then BORE_PORT=$$p; BORE_PID=$$bpid; break; fi; \
		wait $$bpid 2>/dev/null || true; \
	done; \
	if [ -z "$$BORE_PORT" ]; then \
		echo "!! aucun port libre dans: $(PUBLISH_PORTS)"; \
		echo "   (dernier log bore: $$(cat /tmp/bore-publish.log))"; \
		exit 1; \
	fi; \
	echo ">> publie sur bore.pub:$$BORE_PORT  ->  ssh -p $$BORE_PORT bore.pub"; \
	trap 'kill $$BORE_PID $$SOFT_PID 2>/dev/null' EXIT INT TERM; \
	$(SERVE_ENV) SOFT_SERVE_SSH_PUBLIC_URL="ssh://bore.pub:$$BORE_PORT" ./bin/soft serve & \
	SOFT_PID=$$!; \
	echo ">> soft serve lance (pid $$SOFT_PID) | clone: git clone ssh://bore.pub:$$BORE_PORT/<repo>"; \
	wait $$SOFT_PID

## migrate: Apply database migrations (SQLite / PostgreSQL)
migrate: build
	./bin/soft admin migrate

## rollback: Rollback database to the previous migration step
rollback: build
	./bin/soft admin rollback

## sync-hooks: Synchronize and install Git hooks in all bare repositories
sync-hooks: build
	./bin/soft admin sync-hooks

## test: Run Go and PHPUnit test suites
test:
	cd cmd/gickers-ssh && $(GO) test ./...
	./vendor/bin/phpunit

## lint: Run every static check (cs-check, stan, deptrac, container/yaml lint)
lint: cs-check stan deptrac
	./bin/console lint:container
	./bin/console lint:yaml config

## cs: Fix coding style (php-cs-fixer, @Symfony)
cs:
	PHP_CS_FIXER_IGNORE_ENV=1 ./vendor/bin/php-cs-fixer fix

## cs-check: Check coding style without modifying files
cs-check:
	PHP_CS_FIXER_IGNORE_ENV=1 ./vendor/bin/php-cs-fixer fix --dry-run --diff

## stan: Run PHPStan (level max + baseline)
stan:
	./bin/console cache:warmup --env=dev >/dev/null
	./vendor/bin/phpstan analyse --memory-limit=1G

## deptrac: Check module/layer dependency rules
deptrac:
	./vendor/bin/deptrac analyse --no-progress --no-cache

## test-render: Test Termstrap FFI rendering bridge (Markdown, CSV, ANSI TokyoNight)
test-render: build
	./bin/console dev:render-smoke

## clean: Remove build artifacts and temporary cache
clean:
	@rm -rf bin/gickers-ssh bin/codes-ssh /tmp/bore-publish.log

## clean-all: Remove temporary cache AND local database / repositories (.data/)
clean-all: clean
	@rm -rf $(DATA_DIR)

## upstream-setup: Register the charmbracelet/soft-serve fork source as the 'upstream' remote (push disabled)
upstream-setup:
	@git remote get-url $(UPSTREAM_REMOTE) >/dev/null 2>&1 \
		&& git remote set-url $(UPSTREAM_REMOTE) $(UPSTREAM_URL) \
		|| git remote add $(UPSTREAM_REMOTE) $(UPSTREAM_URL)
	@git remote set-url --push $(UPSTREAM_REMOTE) DISABLE
	@echo "Remote '$(UPSTREAM_REMOTE)' -> $(UPSTREAM_URL) (push disabled)"

## upstream-fetch: Fetch upstream branches and tags
upstream-fetch: upstream-setup
	git fetch $(UPSTREAM_REMOTE) --tags --prune

## upstream-status: Show how far the current branch is ahead/behind upstream
upstream-status: upstream-fetch
	@echo "ahead / behind  (HEAD...$(UPSTREAM_REMOTE)/$(UPSTREAM_BRANCH)):"; \
	git rev-list --left-right --count HEAD...$(UPSTREAM_REMOTE)/$(UPSTREAM_BRANCH)

## upstream-sync: Fetch and merge upstream/main into the current branch (commit local work first)
upstream-sync: upstream-fetch
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "Working tree not clean; commit or stash before syncing." && exit 1; \
	fi
	git merge --no-edit $(UPSTREAM_REMOTE)/$(UPSTREAM_BRANCH)

## help: Display this help message
help:
	@echo "Usage: make [target]"
	@echo ""
	@echo "Targets:"
	@sed -n 's/^## //p' $(MAKEFILE_LIST) | column -t -s ':'
