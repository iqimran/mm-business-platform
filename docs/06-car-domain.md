# Car Domain

## Responsibilities
- Cars
- Dealers
- Parties
- Expense types
- Expenses
- Sales
- Payment history
- Financial summary
- Images
- Excel imports
- Reports

## Financial model

### Profit
Sale Price - Purchase Cost - Car Expenses = Profit

### Party balance
Sale Amount - Payments Received = Party Due

### Dealer payable
Purchase Amount - Payments Made = Dealer Payable

Do not mix these calculations.

## Lifecycle
PURCHASED → IN_STOCK → PREPARATION → READY_FOR_SALE → SOLD → COMPLETED

Status list may evolve, but transitions must be explicit and validated. A SOLD car must not be sold again accidentally.

## Car Profile
Primary business view for one car:
- Basic information
- Purchase information
- Dealer
- Images
- Expenses
- Sale information
- Payment history
- Party balance
- Dealer balance
- Total investment
- Sale price
- Profit
- Timeline
- Audit history

## Financial integrity
Car sale, payment, expense, dealer payment and party payment are business operations. Use service/domain actions, transactions, validation, authorization and audit logging.

## Required financial tests
At minimum:
- Purchase
- Expense
- Sale
- Partial payment
- Full payment
- Multiple payments
- Party due
- Dealer payable
- Profit calculation
- Reversal/correction
- Branch isolation
- Unauthorized access

Example:
Purchase = 700000
Expenses = 55000
Sale = 850000
Expected Profit = 95000
