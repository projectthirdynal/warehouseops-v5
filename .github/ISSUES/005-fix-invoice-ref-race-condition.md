---
title: "fix(finance): Invoice::generateRef() race condition"
labels: bug, finance, P1
---

## What to build

`Invoice::generateRef()` and `SupplierInvoice::generateRef()` use `self::whereYear('created_at', $year)->count() + 1` to generate sequential reference numbers. This is not atomic — concurrent requests can generate the same reference, causing a unique constraint violation.

## Acceptance criteria

- [ ] Reference generation is atomic (e.g., `SELECT FOR UPDATE` counter table, or retry on unique constraint violation).
- [ ] Concurrent invoice creation does not produce duplicate refs.
- [ ] Same fix applied to both `Invoice` and `SupplierInvoice`.

## Blocked by

None — can start immediately.
