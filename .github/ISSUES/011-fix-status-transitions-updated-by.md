---
title: "fix(finance): status transitions don't set updated_by"
labels: bug, finance, audit, P1
---

## What to build

Methods `validateInvoice()`, `send()`, and `cancel()` in both `InvoiceController` and `SupplierInvoiceController` update the invoice status but never set `updated_by`. This breaks the audit trail for who changed the status.

## Acceptance criteria

- [ ] All status transitions set `updated_by` to the current authenticated user's ID.
- [ ] Tests verify `updated_by` is populated after each transition.

## Blocked by

None — can start immediately.
