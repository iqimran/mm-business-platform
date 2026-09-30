.DEFAULT_GOAL := help
SHELL := /bin/bash

.PHONY: help init doctor check-secrets setup up down restart ps logs shell artisan composer

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

init: ## Create local .env from .env.example (never overwrites)
	@if [ -f .env ]; then echo ".env already exists — skipped"; else cp .env.example .env && echo "Created .env from .env.example"; fi

doctor: ## Check required local tooling
	@status=0; \
	for tool in git make docker; do \
		if command -v $$tool >/dev/null 2>&1; then echo "  ok      $$tool"; else echo "  MISSING $$tool"; status=1; fi; \
	done; \
	if docker compose version >/dev/null 2>&1; then echo "  ok      docker compose"; else echo "  MISSING docker compose"; status=1; fi; \
	exit $$status

check-secrets: ## Fail if any real env/key files are tracked or staged
	@files=$$(git ls-files --cached | grep -E '(^|/)\.env(\..+)?$$|\.(pem|key|p12|pfx)$$' | grep -vE '\.example$$' || true); \
	if [ -n "$$files" ]; then echo "Secret files tracked:"; echo "$$files"; exit 1; else echo "No secret files tracked"; fi

setup: init ## First-time setup: build images, install dependencies, start stack
	docker compose build
	docker compose run --rm --no-deps backend sh -c "composer install && ([ -f .env ] || (cp .env.example .env && php artisan key:generate))"
	docker compose up -d

up: ## Start all services in the background
	docker compose up -d

down: ## Stop all services (data volumes are kept)
	docker compose down

restart: ## Restart all services
	docker compose restart

ps: ## Show service status
	docker compose ps

logs: ## Follow logs (optionally: make logs s=backend)
	docker compose logs -f $(s)

shell: ## Open a shell in the backend container
	docker compose exec backend sh

artisan: ## Run artisan in the backend (e.g. make artisan c="about")
	docker compose exec backend php artisan $(c)

composer: ## Run composer in the backend (e.g. make composer c="require vendor/pkg")
	docker compose exec backend composer $(c)
