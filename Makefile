SHELL := /bin/sh

# PHP CLI & Composer
PHP ?= php
COMPOSER ?= composer

# Directories & Configuration (exported to bin/soft; see .env for every SOFT_SERVE_* variable)
DATA_DIR ?= .data
SOFT_SERVE_DATA_PATH ?= $(CURDIR)/$(DATA_DIR)
SOFT_SERVE_INITIAL_ADMIN_KEYS ?= $(shell cat ~/.ssh/id_ed25519.pub 2>/dev/null || cat ~/.ssh/id_rsa.pub 2>/dev/null || echo "")

# Server Network Settings
SSH_PORT ?= 2223
SSH_HOST ?= 127.0.0.1
SSH_PUBLIC_URL ?= ssh://localhost:$(SSH_PORT)
HTTP_PORT ?= 23232
HTTP_HOST ?= 127.0.0.1
HTTP_PUBLIC_URL ?= http://localhost:$(HTTP_PORT)

SERVE_ENV = SOFT_SERVE_DATA_PATH="$(SOFT_SERVE_DATA_PATH)" \
	SOFT_SERVE_INITIAL_ADMIN_KEYS="$(SOFT_SERVE_INITIAL_ADMIN_KEYS)" \
	SOFT_SERVE_SSH_LISTEN_ADDR="$(SSH_HOST):$(SSH_PORT)" \
	SOFT_SERVE_HTTP_LISTEN_ADDR="$(HTTP_HOST):$(HTTP_PORT)" \
	SOFT_SERVE_HTTP_PUBLIC_URL="$(HTTP_PUBLIC_URL)"

# Tunneling / Publish ports
PUBLISH_PORTS ?= 2220 2221 2222 2223 2224 2225 2226 2227 2228 2229

# Upstream synchronization
UPSTREAM_URL ?= https://github.com/charmbracelet/soft-serve.git
UPSTREAM_REMOTE ?= upstream
UPSTREAM_BRANCH ?= main

.PHONY: all build setup install run serve debug e2e browse publish index migrate rollback sync-hooks test test-render lint cs cs-check stan deptrac clean clean-all help upstream-setup upstream-fetch upstream-status upstream-sync

all: build

## build: Make CLI entry points executable (bin/soft, bin/console)
build:
	@chmod +x bin/soft bin/console

## setup: Install / check system tools (bore, scip-go indexer)
setup:
	@echo "==> Verification des outils d'environnement..."
	@which $(PHP) >/dev/null 2>&1 || { echo "!! php introuvable"; exit 1; }
	@which $(COMPOSER) >/dev/null 2>&1 || { echo "!! composer introuvable"; exit 1; }
	@which git >/dev/null 2>&1 || { echo "!! git introuvable"; exit 1; }
	@echo "==> Verification de bore (tunneling public codes.sh)..."
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

## run: Run the codes.sh server (sshd :2223, HTTP :23232, git daemon, Messenger worker)
run: build
	$(SERVE_ENV) SOFT_SERVE_SSH_PUBLIC_URL="$(SSH_PUBLIC_URL)" ./bin/soft serve

## serve: Alias of run
serve: run

## debug: Run the server with verbose output (process command lines, debug logs)
debug: build
	$(SERVE_ENV) SOFT_SERVE_SSH_PUBLIC_URL="$(SSH_PUBLIC_URL)" LOG_LEVEL=debug ./bin/soft serve -vv

## e2e: End-to-end run on throwaway ports/data: ssh repo create, git push over SSH, clone over HTTP and git://
e2e: build
	./scripts/e2e.sh

## browse: Launch the codes.sh terminal UI repository browser
browse: build
	SOFT_SERVE_DATA_PATH="$(SOFT_SERVE_DATA_PATH)" ./bin/soft browse

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

## index: Run code intelligence SCIP indexing for all repositories (Go To Def & Ref)
index: build
	SOFT_SERVE_DATA_PATH="$(SOFT_SERVE_DATA_PATH)" ./bin/soft admin reindex-codeintel

## migrate: Apply database migrations (SQLite / PostgreSQL)
migrate: build
	SOFT_SERVE_DATA_PATH="$(SOFT_SERVE_DATA_PATH)" ./bin/soft admin migrate

## rollback: Rollback database to the previous migration step
rollback: build
	SOFT_SERVE_DATA_PATH="$(SOFT_SERVE_DATA_PATH)" ./bin/soft admin rollback

## sync-hooks: Synchronize and install Git hooks in all bare repositories
sync-hooks: build
	SOFT_SERVE_DATA_PATH="$(SOFT_SERVE_DATA_PATH)" ./bin/soft admin sync-hooks

## test: Run PHPUnit test suite
test:
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
	./vendor/bin/deptrac analyse --no-progress

## test-render: Test Termstrap FFI rendering bridge (Markdown, CSV, ANSI TokyoNight)
test-render: build
	./bin/console dev:render-smoke

## clean: Remove build artifacts and temporary cache
clean:
	@rm -rf /tmp/bore-publish.log

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
