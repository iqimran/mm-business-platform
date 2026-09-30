# Task 011 — Excel Import Pipeline

## Objective
Implement asynchronous Excel upload/import processing with normalization, validation, master matching/creation and downloadable error results.

## Read
- docs/00-project.md
- docs/07-imports.md, docs/06-car-domain.md, docs/10-testing-security.md

## Scope
### In scope
- the phase named in this task

### Out of scope
- Unrelated modules
- New infrastructure unless explicitly approved

## Requirements
- Implement asynchronous Excel upload/import processing with normalization, validation, master matching/creation and downloadable error results.

## Acceptance Criteria
- Large imports are queued; invalid rows are reported; normalization preserves original values; import activity is auditable.

## Verification
Run the smallest relevant checks first.

## Claude instruction
Implement only this task. Do not modify unrelated files. If a requirement is ambiguous or conflicts with existing architecture, stop and report the conflict before making a broad design change.
