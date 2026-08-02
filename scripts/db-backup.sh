#!/bin/bash
# ============================================
# WarehouseOps Database Backup Script
# Creates a gzipped SQL dump of the warehouseops database
# Usage: ./scripts/db-backup.sh
# ============================================

set -e

BACKUP_DIR="storage/backups/postgres"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/warehouseops-${TIMESTAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

echo "Creating database backup..."

# Get DB credentials from the Docker container's env
DB_NAME=$(docker compose exec -T app printenv DB_DATABASE 2>/dev/null | tr -d '\r\n')
DB_USER=$(docker compose exec -T app printenv DB_USERNAME 2>/dev/null | tr -d '\r\n')
DB_PASSWORD=$(docker compose exec -T app printenv DB_PASSWORD 2>/dev/null | tr -d '\r\n')

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASSWORD" ]; then
    echo "ERROR: Could not read DB credentials from container. Is Docker running?"
    exit 1
fi

echo "Backing up database: ${DB_NAME} (user: ${DB_USER})"

# Run pg_dump inside the postgres container
docker compose exec -T -e PGPASSWORD="${DB_PASSWORD}" postgres \
    pg_dump -U "${DB_USER}" -d "${DB_NAME}" --no-owner --no-acl 2>/dev/null | gzip > "${BACKUP_FILE}"

BACKUP_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
echo "Backup created: ${BACKUP_FILE} (${BACKUP_SIZE})"

# Keep only the last 10 backups
cd "${BACKUP_DIR}"
ls -t warehouseops-*.sql.gz | tail -n +11 | xargs -r rm --
echo "Old backups cleaned (keeping last 10)"

echo "Done."
