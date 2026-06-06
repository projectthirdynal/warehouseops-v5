---
title: "fix(finance): InvoiceController::storeLine() omits derived financial fields"
labels: bug, finance, P0
---

## What to build

When adding a single invoice line via `POST /finance/invoices/{invoice}/lines`, the `storeLine()` method creates an `InvoiceLine` record with only raw inputs (`qty`, `unit_price`, `tax_rate`, `discount_pct`) but does **not** calculate or store the derived fields:

- `total_ht`
- `total_ttc`
- `discount_amount`
- `tax_amount`

These default to 0 in the database. The subsequent `recalculate()` call sums these 0 values, producing **incorrect invoice totals**.

This is a **P0 data integrity bug** — invoices can have lines that show 0 amounts while the total is wrong.

## Acceptance criteria

- [ ] `storeLine()` calculates and stores `total_ht`, `total_ttc`, `discount_amount`, `tax_amount` on the `InvoiceLine` record before creation.
- [ ] `recalculate()` produces correct invoice totals after adding a line.
- [ ] Existing tests still pass.
- [ ] New test covers the financial field calculation.

## Blocked by

None — can start immediately.
