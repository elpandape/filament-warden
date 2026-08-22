DC = UID=$(shell id -u) GID=$(shell id -g) docker compose
PHP = $(DC) run --rm php

PINT_CACHE = --cache-file=.cache/pint.cache

.PHONY: build install update test coverage types stan lint lint-fix rector rector-fix profanity verify helpers ci shell

build: ## Build the dev image
	$(DC) build php

install: ## composer install
	$(PHP) composer install

update: ## composer update
	$(PHP) composer update

test: ## Run the test suite
	$(PHP) vendor/bin/pest --ci --parallel

coverage: ## Tests + 100% coverage gate
	$(PHP) php -d memory_limit=1G -d zend.assertions=1 -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' vendor/bin/pest --ci --parallel --coverage --min=100

types: ## 100% type coverage gate
	$(PHP) php -d memory_limit=1G -d zend.assertions=1 vendor/bin/pest --type-coverage --min=100

profanity: ## Language check over the code
	$(PHP) vendor/bin/pest --profanity

# A duplicate global helper is a fatal at load time, not a failing test: the run
# dies before Pest executes anything, so this cannot live inside the suite.
helpers: ## Check for duplicate global test helpers
	@dup=$$(grep -rhoE '^function [a-zA-Z_][a-zA-Z0-9_]*' tests/ --include=*.php | awk '{print $$2}' | sort | uniq -d); \
	if [ -n "$$dup" ]; then \
		echo 'Duplicate global test helpers:'; echo "$$dup"; exit 1; \
	fi; \
	echo 'No duplicate global test helpers.'

# The only gate that runs the package's JS instead of reading it. `npm ci` is
# offline-idempotent against the committed lock, so the gate stays honest about
# which `@vue/reactivity` it ran under — the whole point is that it is Alpine's.
verify: ## Drive the JS through the reactivity Alpine really uses
	$(PHP) sh -c 'cd verify && npm ci --silent && node verify-reach-of.mjs && node verify-select-sequencing.mjs'

stan: ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Pint check (no changes)
	$(PHP) vendor/bin/pint --test $(PINT_CACHE) --parallel

lint-fix: ## Pint apply
	$(PHP) vendor/bin/pint $(PINT_CACHE) --parallel

rector: ## Rector dry-run
	$(PHP) vendor/bin/rector process --dry-run

rector-fix: ## Rector apply
	$(PHP) vendor/bin/rector process

ci: lint stan rector coverage types profanity verify helpers ## Everything CI runs

shell: ## Shell inside the container
	$(PHP) sh
