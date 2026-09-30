# Testing & Security

## Backend tests
- Unit
- Feature/API
- Authorization
- Database
- Import
- Financial calculation

## Frontend tests
- Component
- Form validation
- API integration

## E2E
Use Playwright for critical business flows.

## Security
Mandatory:
- Password hashing
- Rate limiting
- Input validation
- Authorization policies
- CSRF protection where applicable
- Secure cookies/headers where applicable
- File validation and upload restrictions
- SQL injection prevention
- XSS prevention
- Authentication throttling
- Audit logging
- Environment/configuration secrets
- Never commit secrets

## Error handling
Do not expose SQL errors, stack traces, internal paths, secrets or tokens. Log technical details internally and return meaningful user-facing errors.

## Performance
Optimize based on evidence. Prioritize indexes, query optimization, pagination, eager loading, caching, queues, object storage and efficient API responses. Avoid premature optimization.
