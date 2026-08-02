#!/bin/sh
# Local dev database backup script for warehouseops-postgres.
# Usage: ./scripts/backup-local-db.sh
# Recommended: schedule via cron, e.g.
#   0 * * * * /path/to/warehouseops-v5/scripts/backup-local-db.sh >> /tmp/warehouseops-backup.log 2>&1
#
# Keeps the last 48 backups (~2 days if run hourly, or ~48 days if run daily)
# and prunes older ones automatically.

set -eu

CONTAINER="warehouseops-postgres"
DB_USER="warehouseops"
DB_NAME="warehouseops"
BACKUP_DIR="$(cd "$(dirname "$0")/.." && pwd)/storage/db-backups"
KEEP=48

mkdir -p "$BACKUP_DIR"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
OUT_FILE="$BACKUP_DIR/warehouseops-$TIMESTAMP.sql.gz"

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}\$"; then
    echo "[backup-local-db] ERROR: container '$CONTAINER' is not running. Skipping backup."
    exit 1
fi

docker exec "$CONTAINER" pg_dump -U "$DB_USER" "$DB_NAME" | gzip > "$OUT_FILE"

echo "[backup-local-db] Backup written to $OUT_FILE ($(du -h "$OUT_FILE" | cut -f1))"

# Prune old backups, keeping only the most recent $KEEP
ls -1t "$BACKUP_DIR"/warehouseops-*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f

echo "[backup-local-db] Done. $(ls -1 "$BACKUP_DIR"/warehouseops-*.sql.gz 2>/dev/null | wc -l) backup(s) retained."
