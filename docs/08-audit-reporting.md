# Audit, Reporting, Search, Cache, Queue & Storage

## Audit
Audit important changes including:
- User creation
- Role/permission changes
- Branch changes
- Car creation/update/purchase/expense/sale/payment
- Party/dealer changes
- Excel imports
- Application settings

Audit fields where appropriate:
- user_id
- branch_id
- action
- entity_type
- entity_id
- old_values
- new_values
- ip_address
- user_agent
- created_at

Never log passwords, access tokens or sensitive credentials.

## Reporting
Build reports from the database model, not by scraping frontend data.

Car:
- Total Purchased
- Total Expenses
- Total Sold
- Total Received
- Total Due
- Total Profit

Branch:
- Cars
- Expenses
- Sales
- Receivables
- Profit

Restaurant:
- Food Sales
- Expenses
- Bookings
- Outstanding Payments

## Search
Start with PostgreSQL search. Optimize queries/indexes before introducing Elasticsearch/OpenSearch.

## Cache
Use Redis for cache, queue, rate limiting, and distributed locks where necessary.
Cache keys must include user/branch scope where required to prevent data leakage.

## Queue
Use queues for:
- Excel imports
- Large report generation
- Email
- Notifications
- Image processing
- Other long-running tasks

## File storage
Store uploaded images/files in object storage, not directly in PostgreSQL. Keep metadata in the database.
