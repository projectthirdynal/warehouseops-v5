#!/bin/bash
# ============================================
# WarehouseOps Database Restore Script
# Restores a gzipped SQL dump of the warehouseops database
# Usage: ./scripts/db-restore.sh <backup-file>
# Example: ./scripts/db-restore.sh storage/backups/postgres/warehouseops-20260802_153623.sql.gz
# ============================================

set -e

if [ -z "$1" ]; then
    echo "ERROR: No backup file specified."
    echo "Usage: $0 <backup-file>"
    echo ""
    echo "Available backups:"
    ls -lt storage/backups/postgres/warehouseops-*.sql.gz 2>/dev/null | head -10
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "ERROR: File not found: ${BACKUP_FILE}"
    exit 1
fi

# Get DB credentials from the Docker container's env
DB_NAME=$(docker compose exec -T app printenv DB_DATABASE 2>/dev/null | tr -d '\r\n')
DB_USER=$(docker compose exec -T app printenv DB_USERNAME 2>/dev/null | tr -d '\r\n')
DB_PASSWORD=$(docker compose exec -T app printenv DB_PASSWORD 2>/dev/null | tr -d '\r\n')

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASSWORD" ]; then
    echo "ERROR: Could not read DB credentials from container. Is Docker running?"
    exit 1
fi

echo "Restoring backup: ${BACKUP_FILE}"
echo "Target database: ${DB_NAME} (user: ${DB_USER})"
echo ""
read -p "This will OVERWRITE the current database. Continue? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo "Aborted."
    exit 0
fi

echo "Restoring..."
gunzip -c "$BACKUP_FILE" | docker compose exec -T -e PGPASSWORD="${DB_PASSWORD}" postgres \
    psql -U "${DB_USER}" -d "${DB_NAME}" 2>&1

echo ""
echo "Restore complete."
echo ""
echo "Verifying..."
docker compose exec -T app php artisan tinker --execute="echo 'Users: ' . \App\Models\User::count() . PHP_EOL . 'Leads: ' . \App\Domain\Lead\Models\Lead::count() . PHP_EOL . 'Orders: ' . \App\Domain\Order\Models\Order::count() . PHP_EOL . 'Customers: ' . \App\Models\Customer::count() . PHP_EOL . 'Waybills: ' . \App\Domain\Waybill\Models\Waybill::count();" 2>/dev/null
