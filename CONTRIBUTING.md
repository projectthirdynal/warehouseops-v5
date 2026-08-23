# Contributing to WarehouseOps

## Development Workflow

We use a GitFlow-style branching strategy. Feature branches merge into `develop` via PR. `develop` merges into `main` for production releases.

### Branch Structure

| Branch       | Purpose                                                          |
| ------------ | ---------------------------------------------------------------- |
| `main`       | Production-ready code. Protected — no direct pushes. Auto-deploys to production. |
| `develop`    | Integration branch. Protected — no direct pushes. Auto-deploys to staging. |
| `feature/*`  | New features / enhancements — branch from `develop`             |
| `fix/*`      | Bug fixes — branch from `develop`                                |
| `refactor/*` | Code refactors — branch from `develop`                           |
| `chore/*`    | Maintenance, deps, config — branch from `develop`               |
| `hotfix/*`   | Critical production fixes — branch from `main`, merge to both `main` and `develop` |

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
# Always sync with latest develop first
git checkout develop
git pull origin develop

# Create your branch
git checkout -b feature/shop-module
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
git push -u origin feature/shop-module

# Open PR against develop in Gitea
# Go to http://192.168.0.15:3002/it-admin/warehouseops-v5/compare/develop...feature/shop-module
```

All feature PRs go against **`develop`**. Fill out the PR template.

### 3b. Release to Production

When `develop` is ready for production release:

1. Open a PR from `develop` → `main` in Gitea
2. Get approval from project owner
3. Merge — this **auto-deploys to production** via the Gitea Actions workflow

### 4. Code Review Requirements

- All CI checks must pass (build, lint, typecheck)
- At least **1 approving review** from a team member
- No merge conflicts
- Branch must be up to date with `develop`

### 5. Merge

Use **Squash and Merge** for clean history. Delete your branch after merge.

```bash
# After your PR is merged
git checkout develop
git pull origin develop
git branch -d feature/shop-module  # delete local branch
```

## Scope Boundaries

To avoid merge conflicts between developers, respect these ownership boundaries:

| Area         | Owner         | Files                                                                                                        |
| ------------ | ------------- | ------------------------------------------------------------------------------------------------------------ |
| Shop module  | Akiromi       | `resources/js/pages/Shop/*`, `app/Http/Controllers/ShopController.php`, shop routes, shop migrations & views |
| UI/Layout    | Project Owner | `resources/js/layouts/*`, `resources/js/components/ui/*`                                                     |
| Shared files | Coordinate    | `routes/web.php`, `resources/js/layouts/AppLayout.tsx` (navigation), `resources/js/components/*` (shared)    |

If you need to touch shared files, mention it in your PR and tag the relevant owner for review.

## Local Development

```bash
# Clone the repo
git clone http://192.168.0.15:3002/it-admin/warehouseops-v5.git
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
4. After merge to `main` (auto-deploys), cherry-pick or merge to `develop` to keep them in sync
5. Delete the hotfix branch
