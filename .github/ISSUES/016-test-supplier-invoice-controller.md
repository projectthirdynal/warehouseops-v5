---
title: "test(finance): add Pest tests for SupplierInvoiceController"
labels: testing, finance, P2
---

## What to build

`SupplierInvoiceController` has zero tests. It handles supplier invoice creation, validation, and cancellation.

## Acceptance criteria

- [ ] Test `store()` creates a supplier invoice with correct totals.
- [ ] Test `validateInvoice()` transitions DRAFT → VALIDATED.
- [ ] Test `cancel()` blocks PAID and PARTIAL invoices.

## Blocked by

None — can start immediately.
