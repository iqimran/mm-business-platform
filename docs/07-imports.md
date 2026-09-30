# Excel Import

## Processing architecture

Upload
→ Store File
→ Create Import Job
→ Queue
→ Validate
→ Normalize
→ Match/Create Master Data
→ Create Business Records
→ Generate Import Result

Large files must not be processed synchronously.

## Import job must provide
- Total rows
- Processed rows
- Successful rows
- Failed rows
- Error messages
- Downloadable error report

Never silently ignore invalid rows.

## Normalization
Example variants:
- Service Charge
- service charge
- SERVICE CHARGE
- Service  Charge

Normalize before matching, but preserve the original value. Where useful store:
- original_value
- normalized_value

## Automatic master creation
Missing Car Expense Types may be created automatically if configured:
- Normalize first
- Check existing records
- Avoid duplicates
- Respect branch/global scope
- Record automatic creation in import logs
