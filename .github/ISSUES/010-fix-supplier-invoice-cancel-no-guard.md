---
title: "fix(finance): SupplierInvoiceController::cancel() has no status guard"
labels: bug, finance, P1
---

## What to build

`SupplierInvoiceController::cancel()` accepts any invoice and immediately sets status to `CANCELLED` without checking current status. A `PAID` or `PARTIAL` supplier invoice can be cancelled, orphaning payments.

## Acceptance criteria

- [ ] Block cancellation if status is `PAID` or `PARTIAL`.
- [ ] Require `cancel_reason` input.
- [ ] Test that cancelling a paid supplier invoice returns an error.

## Blocked by

None — can start immediately.
