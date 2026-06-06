---
title: "feat(finance): command to mark overdue invoices"
labels: feature, finance, P2
---

## What to build

The `OVERDUE` status is defined in the enum and UI but never actively set. Invoices past their `date_due` remain in `SENT` or `PARTIAL` status indefinitely.

## Acceptance criteria

- [ ] Create `php artisan invoices:mark-overdue` command.
- [ ] Query invoices with status `SENT` or `PARTIAL` where `date_due < today()`.
- [ ] Transition them to `OVERDUE` status.
- [ ] Schedule in `routes/console.php` (daily at 00:00).

## Blocked by

None — can start immediately.
