# Database Conventions

## Database
PostgreSQL with normalized relational structures.

## Integrity
Use:
- Foreign keys
- Unique constraints
- Check constraints where appropriate
- Indexes
- Transactions

Important invariants should also be enforced at database level where practical.

## IDs
Use a consistent identifier strategy. Prefer ULID/UUID for public identifiers where appropriate. Do not mix strategies randomly.

## Branch scope
Every branch-scoped record must contain a branch reference where applicable, including:
- cars
- car_expenses
- car_sales
- car_payments
- restaurant_sales
- restaurant_expenses
- hall_bookings

## Deletion
Do not blindly use soft deletes everywhere.
- Master data: soft delete may be appropriate.
- Financial history: prefer immutable records or reversal/correction operations.

## Migration rules
Every schema change requires:
1. Migration
2. Model update
3. Validation update
4. API update
5. Tests
6. Documentation if behavior changed

Never rewrite a deployed migration. Create a new migration.

## Seeders
Development seeders should cover:
- Permissions
- Default roles
- Development admin
- Demo branches
- Demo users
- Demo car data
- Demo restaurant data

Never hard-code production credentials.
