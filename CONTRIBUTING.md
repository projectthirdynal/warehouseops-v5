# Contributing to WarehouseOps

## Development Workflow

We use a trunk-based branching strategy. All changes go through PRs into `main`.

### Branch Structure

| Branch       | Purpose                                              |
| ------------ | ---------------------------------------------------- |
| `main`       | Production-ready code. Protected — no direct pushes. |
| `feature/*`  | New features / enhancements                          |
| `fix/*`      | Bug fixes                                            |
| `refactor/*` | Code refactors                                       |
| `chore/*`    | Maintenance, deps, config                            |
| `hotfix/*`   | Critical production fixes (fast-track)               |

### Naming Convention

```
feature/pos-module
fix/pos-payment-rounding
refactor/pos-ui-tokens
chore/pos-deps-update
hotfix/auth-bypass
```

### 1. Start Work

```bash
# Always sync with latest main first
git checkout main
git pull origin main

# Create your branch
git checkout -b feature/pos-module
```

### 2. Commit Changes

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add POS checkout flow
fix: prevent rounding error in POS totals
docs: update POS API documentation
test: add POS cart calculation tests
refactor: simplify POS payment logic
```

### 3. Push & Open PR

```bash
git push -u origin feature/pos-module

# Open PR against main
gh pr create --base main --head feature/pos-module \
  --title "feat: POS module" \
  --body "Description of what was built"
```

All PRs go against **`main`**. Fill out the PR template.

### 4. Code Review Requirements

- All CI checks must pass (build, lint, typecheck)
- At least **1 approving review** from a team member
- No merge conflicts
- Branch must be up to date with `main`

### 5. Merge

Use **Squash and Merge** for clean history. Delete your branch after merge.

```bash
# After your PR is merged
git checkout main
git pull origin main
git branch -d feature/pos-module  # delete local branch
```

## Scope Boundaries

To avoid merge conflicts between developers, respect these ownership boundaries:

| Area         | Owner         | Files                                                                                             |
| ------------ | ------------- | ------------------------------------------------------------------------------------------------- |
| POS module   | Akiromi       | `resources/js/pages/Shop/POS/*`, `app/Domain/Shop/Http/Controllers/Pos*`, POS migrations & routes |
| UI/Layout    | Project Owner | `resources/js/layouts/*`, `resources/js/components/ui/*`                                          |
| Shared files | Coordinate    | `routes/web.php`, `resources/js/layouts/AppLayout.tsx` (navigation)                               |

If you need to touch shared files, mention it in your PR and tag the relevant owner for review.

## Local Development

```bash
# Clone the repo
git clone https://github.com/projectthirdynal/warehouseops-v5.git
cd warehouseops-v5

# Backend setup
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve

# Frontend setup
npm install
npm run dev

# Testing
composer test          # Run Pest tests
npm run lint           # Run ESLint
npm run build          # TypeScript + Vite build
```

## Quality Gates

Before opening a PR, ensure:

1. **Build succeeds**: `npm run build`
2. **Lint clean**: `npm run lint`
3. **Tests pass**: `composer test` (if backend changes)
4. **No hardcoded colors** — use semantic tokens (`text-primary`, `bg-card`, etc.)
5. **Typography**: `font-display` on headings, `tabular-nums` on numbers

## Emergency Hotfix Process

1. Branch from `main`: `git checkout -b hotfix/critical-fix`
2. Apply minimal fix, open PR against `main`
3. Tag team lead for **expedited review**
4. After merge, delete the hotfix branch
