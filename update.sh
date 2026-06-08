#!/bin/bash
# ============================================
# WarehouseOps v5 — Git-based Update Script
# Run on the server: sudo bash update.sh
# ============================================

set -e

APP_DIR="/opt/warehouseops"
BRANCH="${1:-main}"

echo "=========================================="
echo "  WarehouseOps — Deploying from GitHub"
echo "  Branch: $BRANCH"
echo "=========================================="

cd "$APP_DIR"

# ── 1. Pull latest code ──────────────────────
echo "[1/6] Pulling latest code from GitHub..."
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"
echo "      → Now at: $(git log --oneline -1)"

# ── 2. Run DB migrations ─────────────────────
echo "[2/6] Running migrations..."
docker compose exec -T app php artisan migrate --force

# ── 3. Clear all caches ──────────────────────
echo "[3/6] Clearing application caches..."
docker compose exec -T app php artisan optimize:clear

# ── 4. Rebuild JS assets ─────────────────────
echo "[4/6] Building frontend assets..."
docker compose exec -T app npm run build

# ── 5. Re-cache for production ───────────────
echo "[5/6] Caching config/routes/views..."
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

# ── 6. Restart queue workers ─────────────────
echo "[6/6] Restarting queue workers..."
docker compose exec -T app php artisan queue:restart || true

echo ""
echo "=========================================="
echo "  Deploy complete! Commit: $(git log --oneline -1)"
echo "=========================================="
