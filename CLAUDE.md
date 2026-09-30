# Claude Development Instructions

## Project Mission
Build a long-term, production-grade, multi-branch business management platform for a business owner who operates:
- Car Business
- Restaurant Business
- Real Estate Software Business - future module

The platform must provide:
- Central authentication
- Central authorization
- Dynamic roles and permissions
- Multi-branch support
- Business-module isolation
- Financial transaction integrity
- Auditability
- Excel import/export
- Reporting
- Secure REST APIs
- Professional responsive web interface
- Long-term maintainability

The application must be designed as a modular monolith rather than microservices unless a future requirement explicitly justifies service extraction.

## Non-negotiable architecture
- Frontend: Next.js + TypeScript + Tailwind CSS + shadcn/ui + React Hook Form + Zod + TanStack Query
- Backend: Laravel + PHP 8.2+ Central Laravel Authentication initially + RBAC + Permission system
- Database: PostgreSQL
- Redis for cache/queue/rate limiting/locks where appropriate
- REST API under `/api/v1/...`
- Docker Compose for local development
- S3-compatible object storage for uploaded files/images
- Reverse proxy: Nginx
- No microservices or new infrastructure unless explicitly approved and justified.

## Working rules
1. Read only the task file and the referenced context files before implementation.
2. Inspect related existing code before changing it.
3. Do not modify unrelated modules.
4. Do not replace libraries without a concrete reason.
5. Do not create duplicate abstractions or duplicate API endpoints.
6. Never bypass authentication, authorization, branch isolation, validation, or audit requirements.
7. Keep business logic out of controllers and large frontend components.
8. Financial operations must use business actions/services, validation, authorization, transactions, and audit logging.
9. Never expose secrets, tokens, SQL errors, stack traces, or internal paths.
10. Do not remove or weaken tests to make a change pass.
11. Preserve backward compatibility unless the task explicitly requires a breaking change.
12. Prefer the smallest correct implementation.

## Token/context discipline
- Do not read the entire documentation tree unless explicitly requested.
- Follow the `Read` list in each task.
- If a required fact is missing, inspect the smallest relevant source file instead of broad-scanning the repository.
- Keep responses concise.
- Do not paste large unchanged files in the response.
- Report changed files, tests, verification, and blockers only.

## Before coding
For significant features:
1. Understand the requirement.
2. Inspect related module(s).
3. Inspect relevant database/API/auth conventions.
4. Identify dependencies and edge cases.
5. State a short plan.
6. Implement only the approved task.

## Verification
Run the smallest relevant checks first, then broader checks when practical:
- Backend: PHPUnit/Pest, lint/static checks used by the project.
- Frontend: typecheck/lint/tests.
- E2E: Playwright for critical flows.

For branch-scoped and financial features, explicitly verify authorization and branch isolation.

## Git
Use small meaningful commits. Do not rewrite git history unless explicitly requested.

## Response format
For significant implementation tasks:

## Understanding
Brief.

## Impact
Affected modules/files.

## Plan
Short steps.

## Database Changes
Only if applicable.

## API Changes
Only if applicable.

## Authorization
Permissions and branch rules.

## Implementation
Concise summary.

## Tests
Commands and result.

## Verification
Authorization, branch isolation, regression status.

## Notes
Assumptions/blockers only.
