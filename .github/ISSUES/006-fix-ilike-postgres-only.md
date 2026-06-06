---
title: "fix(finance): Invoice::scopeSearch() uses PostgreSQL-only ilike"
labels: bug, finance, portability, P2
---

## What to build

`Invoice::scopeSearch()` uses `ilike` for case-insensitive searching. This operator only exists in PostgreSQL and will cause SQL errors on MySQL or SQLite.

## Acceptance criteria

- [ ] Replace `ilike` with a cross-database compatible pattern (e.g., `LOWER(column) LIKE LOWER(?)`).
- [ ] Verify search still works on PostgreSQL (production) and SQLite (tests).

## Blocked by

None — can start immediately.
