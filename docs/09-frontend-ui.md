# Frontend & UI

## Rules
- Backend remains authoritative for authorization.
- Frontend may hide unavailable UI for usability only.
- Use TypeScript, Zod, React Hook Form, TanStack Query.
- Use reusable components.
- Avoid giant page components.
- Keep feature-specific logic near the feature.

## Internal business UI priorities
- Speed
- Clarity
- Keyboard usability
- Responsive layout
- Data tables
- Search
- Filtering
- Pagination
- Bulk operations
- Confirmation dialogs
- Clear financial summaries

Avoid unnecessary animations. Use consistent terminology.

## Tables
Where appropriate:
- Search
- Filtering
- Sorting
- Pagination
- Column visibility
- Export
- Date filtering
- Branch filtering
- Status filtering

Use server-side pagination; do not load thousands of records into the browser.
