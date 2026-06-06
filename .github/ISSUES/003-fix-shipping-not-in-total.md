---
title: "fix(finance): shipping_amount excluded from invoice total"
labels: bug, finance, P0
---

## What to build

The `invoices` table has a `shipping_amount` column, but `recalculate()` only sums line totals (`total_ttc`). Shipping charges are never added to the invoice `total_amount`, causing under-charging when shipping is present.

## Acceptance criteria

- [ ] `recalculate()` includes `shipping_amount` in `total_amount`.
- [ ] Formula: `total_amount = subtotal - discount_amount + tax_amount + shipping_amount`.

## Blocked by

- #2 (recalculate fix)
