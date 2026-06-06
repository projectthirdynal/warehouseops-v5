---
title: "fix(finance): InvoicePayment lacks SoftDeletes"
labels: bug, finance, P1
---

## What to build

The `Invoice` model uses `SoftDeletes`, but `InvoicePayment` does not. If an invoice is soft-deleted, its associated payments remain in the database as orphan records. This breaks data consistency and can cause reporting errors.

## Acceptance criteria

- [ ] Add `SoftDeletes` trait to `InvoicePayment` model.
- [ ] Create migration adding `deleted_at` column to `invoice_payments` table.
- [ ] When an invoice is soft-deleted, its payments are also soft-deleted (via model observer or service layer).

## Blocked by

None — can start immediately.
