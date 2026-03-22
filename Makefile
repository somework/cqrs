.DEFAULT_GOAL := help
ARGS ?=

.PHONY: all test phpstan fix check-style shell composer-install composer-update help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

all: check-style phpstan test ## Run all checks (cs-fixer dry-run + phpstan + tests)

test: ## Run PHPUnit tests
	docker compose run --rm php vendor/bin/phpunit $(ARGS)

phpstan: ## Run PHPStan static analysis
	docker compose run --rm php vendor/bin/phpstan analyse $(ARGS)

fix: ## Apply code style fixes
	docker compose run --rm php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes $(ARGS)

check-style: ## Check code style (dry-run)
	docker compose run --rm php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes --dry-run --diff $(ARGS)

shell: ## Open interactive shell in container
	docker compose run --rm php sh

composer-install: ## Install Composer dependencies
	docker compose run --rm php composer install $(ARGS)

composer-update: ## Update Composer dependencies
	docker compose run --rm php composer update $(ARGS)
