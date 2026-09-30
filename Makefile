.DEFAULT_GOAL := help
SHELL := /bin/bash

.PHONY: help init doctor check-secrets

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
