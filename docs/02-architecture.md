# Architecture

## Stack

### Frontend
- Next.js
- TypeScript
- Tailwind CSS
- shadcn/ui
- React Hook Form
- Zod
- TanStack Query

### Backend
- Laravel
- PHP 8.2+
- PostgreSQL
- Redis
- Laravel Queue
- REST API
- OpenAPI
- PHPUnit/Pest

### Infrastructure
- Docker / Docker Compose
- Nginx
- S3-compatible object storage
- GitHub Actions
- Production environment variables
- Monitoring and error tracking

## Logical architecture

Next.js + TypeScript
→ REST API
→ Laravel
→ Identity / Administration / Branch / Car / Restaurant / Finance / Audit / Reporting / Shared
→ PostgreSQL
with Redis and object storage as supporting infrastructure.

## Backend module structure

`app/Modules/`
- Identity/
- Administration/
- Branch/
- Car/
- Restaurant/
- Finance/
- Audit/
- Reporting/
- Shared/

## Frontend structure

`src/`
- app/
- components/
- features/
- hooks/
- lib/
- types/
- config/

Feature-specific logic should stay close to its feature.

## Principles
- Prefer simplicity, correctness, maintainability.
- Keep domains logically separated.
- Avoid giant global collections of controllers/services/models.
- Do not add Elasticsearch/OpenSearch, event brokers, Kubernetes, or other infrastructure without demonstrated need.
