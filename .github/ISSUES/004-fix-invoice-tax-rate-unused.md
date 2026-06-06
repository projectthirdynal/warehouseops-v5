---
title: "fix(finance): invoice-level tax_rate validated but never applied"
labels: bug, finance, P1
---

## What to build

`InvoiceController::update()` validates `tax_rate` as a field but stores it on the invoice model without ever applying it to calculations. The UI shows a tax rate field that has no effect on totals.

When a line has no explicit `tax_rate`, it should fall back to the invoice-level `tax_rate`.

## Acceptance criteria

- [ ] If a line's `tax_rate` is null/0, the invoice's `tax_rate` is used during line creation.
- [ ] `update()` propagates a changed invoice `tax_rate` to all lines that use the default rate.
- [ ] Tests verify tax is applied correctly.

## Blocked by

- #1 (storeLine fix)
