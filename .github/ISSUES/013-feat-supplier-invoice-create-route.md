---
title: "feat(finance): add /create route for supplier invoices"
labels: feature, finance, P2
---

## What to build

`SupplierInvoiceController` has a `store()` method but no `create()` route or method. The frontend "New" button on the supplier invoices index page has nowhere to navigate.

## Acceptance criteria

- [ ] Add `SupplierInvoiceController::create()` method returning a minimal Create page.
- [ ] Add `GET /finance/supplier-invoices/create` route.
- [ ] Add minimal `Create.tsx` page with form fields: supplier, date, amount, tax, notes.

## Blocked by

None — can start immediately.
