---
title: "test(finance): add Pest tests for InvoiceController"
labels: testing, finance, P1
---

## What to build

`InvoiceController` handles money, status transitions, and payments. It currently has zero tests.

## Acceptance criteria

- [ ] Test `store()` creates an invoice with correct totals.
- [ ] Test `storeLine()` adds a line with correct derived fields.
- [ ] Test `validateInvoice()` transitions DRAFT → VALIDATED.
- [ ] Test `send()` transitions VALIDATED → SENT.
- [ ] Test `cancel()` blocks PAID and PARTIAL invoices.
- [ ] Test `storePayment()` updates amount_paid, amount_due, and status correctly.
- [ ] Test `destroyLine()` recalculates totals.

## Blocked by

- #1 (storeLine fix — test the fixed behavior)
