---
title: "fix(finance): recalculate() double-accounts discount"
labels: bug, finance, P0
---

## What to build

`InvoiceController::recalculate()` computes:
- `subtotal = sum(lines.total_ht)` — but `total_ht` is **already post-discount**
- `discount_amount = sum(lines.discount_amount)`

This means `subtotal` contains the discounted amount, while `discount_amount` is also reported separately. The `subtotal` should represent the pre-discount amount (`qty * unit_price`), and `total_amount` should be `subtotal - discount + tax + shipping`.

## Acceptance criteria

- [ ] `subtotal` is calculated as the sum of pre-discount line amounts (`qty * unit_price`).
- [ ] `total_amount = subtotal - discount_amount + tax_amount + shipping_amount`.
- [ ] Existing invoice totals remain correct or are corrected during migration.

## Blocked by

- #1 (storeLine fix — ensure lines have correct `total_ht` first)
