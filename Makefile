.PHONY: up down restart logs shell test lint format fresh

up: ## Build and start the whole stack
	docker compose up -d --build

down: ## Stop the stack
	docker compose down

restart: down up

logs: ## Tail logs of every service
	docker compose logs -f

shell: ## Open a shell in the app container
	docker compose exec app bash

test: ## Run the test suite
	docker compose exec app php artisan test

lint: ## Run Pint (check mode) and Larastan
	docker compose exec app composer lint

format: ## Fix code style with Pint
	docker compose exec app composer format

fresh: ## Drop and re-run all migrations
	docker compose exec app php artisan migrate:fresh
