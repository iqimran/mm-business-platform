# Enterprise Business Management Platform

This package reorganizes the original planning into a token-efficient documentation/task workflow for Claude.

## Recommended usage

1. Put this folder at the root of the project.
2. Keep `CLAUDE.md` at repository root.
3. Give Claude only the current task file plus its `Read` files.
4. Complete one task at a time.
5. Run tests after each meaningful task.
6. Commit small logical changes.
7. Add new task files rather than creating one giant prompt.

## First task
Start with `docs/tasks/001-init-repository.md`.

## Important
The organized files intentionally separate concerns so Claude does not need to load the entire 1,500+ line for every change.

## Repository layout

```
backend/    Laravel API (scaffolded in a later task)
frontend/   Next.js web app (scaffolded in a later task)
docs/       Architecture docs and task files
```

## Local setup

Prerequisites: Git, Make, Docker with Docker Compose.

```bash
make doctor         # check required tooling
make init           # create .env from .env.example (never overwrites)
make check-secrets  # confirm no env/key files are tracked by git
```

`.env` files are git-ignored. Only `*.example` files are committed. Keep real credentials out of the repository.

```bash
make setup          # build images, install backend deps, start the stack
make artisan c="migrate --seed"
```

Services: API via nginx `http://localhost:8080` (health: `/up`), Next.js `http://localhost:3000`,
PostgreSQL and Redis bound to `127.0.0.1`. If a port is already taken, change it in your local `.env`
(`NGINX_PORT`, `FRONTEND_PORT`, `DB_PORT`, `REDIS_PORT`). Tests run against the separate `mm_platform_test` database:
`docker compose exec backend php artisan test`.
