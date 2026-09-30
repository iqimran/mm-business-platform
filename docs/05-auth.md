# Authentication & Authorization

## Authentication
Authentication is centralized. Users do not have separate accounts for each business module.

One user can access:
- Central Administration
- Car Business
- Restaurant Business
- Future Real Estate Business

Authentication answers: who is the user?
Authorization answers: what may the user do?

## Authorization model
Never hard-code authorization based on role names.

Use:
User → Roles → Permissions
User → Branch Access

Default roles may include:
- Super Admin
- Admin
- General User

These are defaults, not hard-coded authorization rules. Administrators can create additional roles.

## Example permissions
- user.view/create/update/delete
- role.view/create/update/delete
- branch.view/create/update
- car.view/create/update/delete/import
- car.expense.view/create/update/delete
- car.sale.view/create/update
- car.payment.view/create
- restaurant.menu.view/create
- restaurant.sale.create
- restaurant.expense.create

## Branch security
Backend authorization must enforce branch access. Frontend filtering is never sufficient.

Never trust client-provided:
- user_id
- branch_id
- role_id
- permission
- created_by

without server-side validation and authorization.

## Security
- Password hashing
- Rate limiting/throttling
- Input validation
- Policies/authorization
- Secure cookies/headers where applicable
- Secrets through environment/configuration
- Audit logging
- Never commit secrets
