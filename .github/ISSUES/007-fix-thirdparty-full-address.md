---
title: "fix(crm): ThirdParty model missing full_address accessor"
labels: bug, crm, P1
---

## What to build

Both `InvoiceController::store()` and `SupplierInvoiceController::store()` reference `$thirdParty?->full_address`, but the `ThirdParty` model has no `full_address` attribute or accessor. The address is always `null` in the frontend.

## Acceptance criteria

- [ ] Add `getFullAddressAttribute()` accessor to `ThirdParty` model.
- [ ] Concatenate `street`, `city`, `state` (and optionally `country`, `postal_code`) into a formatted address string.
- [ ] Handle null fields gracefully (skip empty parts).

## Blocked by

None — can start immediately.
