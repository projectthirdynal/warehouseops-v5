---
title: "chore(devops): setup pre-commit hooks for lint/build/typecheck"
labels: devops, quality, P2
---

## What to build

Recent deploys failed because of TypeScript errors (missing `xlsx` dep, missing type definitions) and frontend build failures (`index.php` wiped by `npm run build`). These should be caught before code reaches CI.

## Acceptance criteria

- [ ] Install Husky + lint-staged.
- [ ] Pre-commit runs `npm run lint` on staged `.{ts,tsx}` files.
- [ ] Pre-commit runs `npm run build` (or at least `tsc --noEmit`).
- [ ] Pre-commit runs `composer analyse` (PHPStan) on staged `.php` files.
- [ ] Pre-commit runs `composer format -- --test` (Laravel Pint dry-run).

## Blocked by

None — can start immediately.
