---
title: "fix(finance): InvoiceController::cancel() allows cancelling PARTIAL invoices"
labels: bug, finance, P1
---

## What to build

`InvoiceController::cancel()` only blocks `PAID` invoices. It allows cancelling `PARTIAL` (partially paid) invoices, which leaves recorded payments without a clear refund or reconciliation process.

## Acceptance criteria

- [ ] Block cancellation if status is `PARTIAL`.
- [ ] Optionally: require refunding recorded payments before allowing cancellation of partial invoices.
- [ ] Same guard applied to `SupplierInvoiceController::cancel()`.

## Blocked by

None — can start immediately.
