# Contributing to WarehouseOps

## Development Workflow

We use a simplified GitFlow branching strategy optimized for continuous delivery.

### Branch Structure

| Branch | Purpose | Deploys To |
|--------|---------|------------|
| `main` | Production-ready code | Production (manual approval) |
| `develop` | Integration branch for features | Staging (auto-deploy) |
| `feature/*` | New features / enhancements | — |
| `fix/*` | Bug fixes | — |
| `hotfix/*` | Critical production fixes | Production (fast-track) |

### Workflow

```
feature/login-redirect ─┐
feature/barcode-scan   ─┼─► develop ──► main ──► production
fix/race-condition    ──┘     ↑              (approval gate)
                              │
                         hotfix/auth-bypass ──┘
```

### 1. Start Work

```bash
# Pull latest
git checkout develop
git pull origin develop

# Create feature branch
git checkout -b feature/my-feature-name
```

### 2. Commit Changes

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add barcode scanner auto-focus
fix: prevent race condition in stock adjustment
docs: update API documentation
test: add unauthorized autoAdjust tests
refactor: simplify product lookup logic
```

### 3. Push & Open PR

```bash
git push -u origin feature/my-feature-name
```

Open a PR against **`develop`**. Fill out the PR template.

### 4. Code Review Requirements

- All CI checks must pass (tests, lint, typecheck, build)
- At least **1 approving review** from a team member
- No merge conflicts
- Branch must be up to date with `develop`

### 5. Merge

Use **Squash and Merge** for clean history. Delete the feature branch after merge.

### 6. Deployment

- `develop` branch → auto-deploys to **staging** after CI passes
- `main` branch → requires **manual approval** to deploy to **production**
- `hotfix/*` branches → can fast-track to `main` with expedited review

## Local Development

```bash
# Backend
composer install
php artisan migrate
php artisan serve

# Frontend
npm install
npm run dev

# Testing
composer test          # Run Pest tests
composer analyse       # Run PHPStan
composer format        # Run Laravel Pint
npm run lint           # Run ESLint
npm run build          # TypeScript + Vite build
```

## Quality Gates

Before opening a PR, ensure:

1. **Tests pass**: `composer test`
2. **Static analysis clean**: `composer analyse`
3. **Code formatted**: `composer format`
4. **Frontend lint clean**: `npm run lint`
5. **Build succeeds**: `npm run build`

## Emergency Hotfix Process

1. Branch from `main`: `git checkout -b hotfix/critical-fix`
2. Apply minimal fix, open PR against `main`
3. Tag team lead for **expedited review** (skip normal queue)
4. After merge, cherry-pick to `develop`

## Rollback

Production deployments create timestamped backups at `/opt/warehouseops-backups/YYYYmmddHHMMSS/`. To rollback:

```bash
sudo rsync -a --delete /opt/warehouseops-backups/<TIMESTAMP>/app /opt/warehouseops/app
sudo docker exec warehouseops-app php artisan optimize:clear
sudo docker exec warehouseops-app php artisan migrate
sudo docker restart warehouseops-app
```
