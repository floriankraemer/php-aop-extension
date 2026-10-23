# Build, test and release tooling. Run `make help`.
SHELL := /bin/bash

PHP        ?= php
PHPIZE     ?= phpize
PHP_CONFIG ?= php-config
COMPOSER   ?= composer
JOBS       ?= $(shell nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 2)

EXT_DIR := ext
EXT_SO  := $(EXT_DIR)/modules/aop.so

# Docker matrix (see scripts/docker-matrix.sh for every knob).
VERSION      ?= $(shell git describe --tags --always 2>/dev/null || echo dev)
PHP_VERSIONS ?= 8.3 8.4 8.5
TS_MODES     ?= nts zts
LIBCS        ?= glibc musl
PLATFORMS    ?=
export VERSION PHP_VERSIONS TS_MODES LIBCS PLATFORMS

COMPOSE ?= docker compose

.PHONY: help
help: ## Show this help
	@echo "Targets:"
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  make %-12s %s\n", $$1, $$2}'
	@echo
	@echo "Variables: VERSION=$(VERSION) PHP_VERSIONS='$(PHP_VERSIONS)' TS_MODES='$(TS_MODES)' LIBCS='$(LIBCS)'"
	@echo "           PLATFORMS=linux/amd64,linux/arm64 (dist, needs QEMU: make binfmt), DRY_RUN=1 prints docker commands"

.PHONY: ext
ext: $(EXT_SO) ## Build the extension natively (phpize/configure/make in ext/)

# Rebuild whenever a source, header or build file changes.
$(EXT_SO): $(wildcard $(EXT_DIR)/*.c $(EXT_DIR)/*.h $(EXT_DIR)/config.m4)
	cd $(EXT_DIR) && ( [ ! -f Makefile ] || $(MAKE) distclean >/dev/null 2>&1 || true )
	cd $(EXT_DIR) && $(PHPIZE) --clean >/dev/null 2>&1 || true
	cd $(EXT_DIR) && $(PHPIZE) && ./configure --enable-aop --with-php-config=$(PHP_CONFIG) && $(MAKE) -j$(JOBS)

.PHONY: test-ext
test-ext: ext ## Run the phpt suite against the built extension
	@# phpize's `make test` copies the system php.ini into tmp-php.ini; a JIT enabled there (setup-php does) makes aop
	@# print its JIT notice in every test with opcache on. Tests that cover the JIT enable it in their --INI-- section.
	cd $(EXT_DIR) && NO_INTERACTION=1 REPORT_EXIT_STATUS=1 TEST_PHP_EXECUTABLE=$$(command -v $(PHP)) \
		TEST_PHP_ARGS="-q -d opcache.jit=disable" $(MAKE) test
	@# Second pass with opcache enabled: its optimizer (function inlining, constant folding) changes what reaches the engine.
	cd $(EXT_DIR) && NO_INTERACTION=1 REPORT_EXIT_STATUS=1 TEST_PHP_EXECUTABLE=$$(command -v $(PHP)) \
		TEST_PHP_ARGS="-q -d opcache.jit=disable $$(../scripts/opcache-args.sh $(PHP))" $(MAKE) test

vendor/autoload.php: composer.json
	$(COMPOSER) install --no-interaction --no-progress
	@touch $@

.PHONY: test
test: ext vendor/autoload.php ## phpunit: unit suite, then e2e with the built aop.so
	$(PHP) vendor/bin/phpunit --testsuite unit
	@if [ -d tests/E2E ]; then \
		$(PHP) -d extension=$(CURDIR)/$(EXT_SO) vendor/bin/phpunit --testsuite e2e; \
	else echo "tests/E2E not present, skipping e2e"; fi

.PHONY: test-examples
test-examples: ext vendor/autoload.php ## Run every examples/NN-*.php and compare with its expected output
	$(PHP) scripts/check-examples.php "$(PHP) -d extension=$(CURDIR)/$(EXT_SO)"

.PHONY: check
check: vendor/autoload.php test-ext ## php-cs-fixer, phpstan, phpt and phpunit
	$(COMPOSER) cs
	$(COMPOSER) stan
	$(MAKE) test
	$(MAKE) test-examples

.PHONY: clean
clean: ## Remove build output (ext/ artefacts, dist/, caches)
	@if [ -f $(EXT_DIR)/Makefile ]; then cd $(EXT_DIR) && $(MAKE) distclean >/dev/null 2>&1 || true; fi
	cd $(EXT_DIR) && $(PHPIZE) --clean >/dev/null 2>&1 || true
	rm -rf dist .phpunit.cache .php-cs-fixer.cache

.PHONY: test-all
test-all: ## In Docker: build + phpt + phpunit on PHP 8.3/8.4/8.5 x cli/zts (Debian)
	scripts/docker-matrix.sh test

.PHONY: dist
dist: ## In Docker: build + phpt + package PHP x NTS/ZTS x glibc/musl into dist/
	scripts/docker-matrix.sh dist

.PHONY: binfmt
binfmt: ## Install QEMU emulators so PLATFORMS=linux/arm64 (or amd64) works
	docker run --privileged --rm tonistiigi/binfmt --install all

.PHONY: test-scripts
test-scripts: ## Unit-check scripts/semver-check.sh
	scripts/test-semver-check.sh

# Development container
.PHONY: build up down shell docker-clean
build: ## Build the dev container
	$(COMPOSE) build
up: ## Start the dev container
	$(COMPOSE) up -d
down: ## Stop the dev container
	$(COMPOSE) down
shell: up ## Shell inside the dev container
	$(COMPOSE) exec php bash
docker-clean: ## Remove the dev container and its volumes
	$(COMPOSE) down -v
