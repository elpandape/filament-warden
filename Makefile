DC = UID=$(shell id -u) GID=$(shell id -g) docker compose
PHP = $(DC) run --rm php

.PHONY: build install update test coverage types stan lint lint-fix rector rector-fix profanity ci shell

build: ## Build the dev image
	$(DC) build php

install: ## composer install
	$(PHP) composer install

update: ## composer update
	$(PHP) composer update

test: ## Run the test suite
	$(PHP) vendor/bin/pest --ci

coverage: ## Tests + 100% coverage gate
	$(PHP) php -d memory_limit=1G -d zend.assertions=1 -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' vendor/bin/pest --ci --coverage --min=100

types: ## 100% type coverage gate
	$(PHP) php -d memory_limit=1G -d zend.assertions=1 vendor/bin/pest --type-coverage --min=100

profanity: ## Language check over the code
	$(PHP) vendor/bin/pest --profanity

stan: ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Pint check (no changes)
	$(PHP) vendor/bin/pint --test

lint-fix: ## Pint apply
	$(PHP) vendor/bin/pint

rector: ## Rector dry-run
	$(PHP) vendor/bin/rector process --dry-run

rector-fix: ## Rector apply
	$(PHP) vendor/bin/rector process

ci: lint stan rector coverage types profanity ## Everything CI runs

shell: ## Shell inside the container
	$(PHP) sh
