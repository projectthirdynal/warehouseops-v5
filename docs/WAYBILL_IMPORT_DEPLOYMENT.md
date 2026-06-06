# Waybill Import System - Deployment Guide

## Prerequisites

- PHP 8.1+
- PostgreSQL 13+
- Redis 6+
- Laravel 10+
- Supervisor (for queue workers)
- Laravel Echo Server or Pusher (for real-time updates)

## Step 1: Database Migration

```bash
# Run the migration
php artisan migrate

# Verify tables created
psql -d warehouseops -c "\dt import_chunks"
psql -d warehouseops -c "\d uploads"
```

Expected output:
- `import_chunks` table created
- `uploads` table has new columns: `uuid`, `file_path`, `total_chunks`, `processed_chunks`, `metadata`, `auto_import`

## Step 2: Redis Configuration

### Update `.env`
```env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

QUEUE_CONNECTION=redis
BROADCAST_DRIVER=redis
```

### Test Redis Connection
```bash
php artisan tinker
>>> Redis::ping()
=> "PONG"
```

### Configure Redis Memory
```bash
# Edit redis.conf
maxmemory 2gb
maxmemory-policy allkeys-lru

# Restart Redis
sudo systemctl restart redis
```

## Step 3: Queue Worker Configuration

### Create Supervisor Config
```bash
sudo nano /etc/supervisor/conf.d/warehouseops-import-worker.conf
```

```ini
[program:warehouseops-import-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/warehouseops/artisan queue:work redis --queue=imports --tries=3 --timeout=600 --sleep=3 --max-jobs=100
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/warehouseops/import-worker.log
stopwaitsecs=3600
```

### Start Workers
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start warehouseops-import-worker:*
sudo supervisorctl status
```

## Step 4: Broadcasting Setup (Optional)

### Option A: Laravel Echo Server

```bash
# Install
npm install -g laravel-echo-server

# Initialize
laravel-echo-server init

# Configure
{
  "authHost": "https://warehouseops.thirdynals.org",
  "authEndpoint": "/broadcasting/auth",
  "clients": [
    {
      "appId": "warehouseops",
      "key": "your-app-key"
    }
  ],
  "database": "redis",
  "databaseConfig": {
    "redis": {
      "host": "127.0.0.1",
      "port": "6379"
    }
  }
}

# Start
laravel-echo-server start
```

### Option B: Pusher

```env
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=your-app-id
PUSHER_APP_KEY=your-app-key
PUSHER_APP_SECRET=your-app-secret
PUSHER_APP_CLUSTER=mt1
```

## Step 5: File Storage Configuration

### Local Storage (Development)
```env
FILESYSTEM_DISK=local
```

### S3 Storage (Production)
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=warehouseops-uploads
AWS_USE_PATH_STYLE_ENDPOINT=false
```

```bash
# Install S3 driver
composer require league/flysystem-aws-s3-v3
```

## Step 6: Environment Variables

Add to `.env`:
```env
# Import Configuration
WAYBILL_CHUNK_SIZE=10000
WAYBILL_BATCH_SIZE=1500
WAYBILL_REDIS_TTL=7200
WAYBILL_MAX_VALIDATION_ROWS=1000
WAYBILL_MAX_FILE_SIZE=102400

# Queue Configuration
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids

# Broadcasting
BROADCAST_DRIVER=redis
```

## Step 7: Clear Caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan optimize
```

## Step 8: Test the System

### Test 1: Upload Small File
```bash
curl -X POST https://warehouseops.thirdynals.org/waybills/import \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@test_100_rows.xlsx" \
  -F "courier=jnt"
```

Expected: Returns `upload_id`

### Test 2: Check Queue
```bash
php artisan queue:monitor redis:imports
```

Expected: Jobs processing

### Test 3: Monitor Progress
```bash
# Watch upload status
watch -n 1 'psql -d warehouseops -c "SELECT id, status, processed_chunks, total_chunks FROM uploads ORDER BY id DESC LIMIT 5"'

# Watch chunk status
watch -n 1 'psql -d warehouseops -c "SELECT upload_id, chunk_number, status, inserted_count, updated_count FROM import_chunks ORDER BY id DESC LIMIT 10"'
```

### Test 4: Check Redis
```bash
redis-cli
> KEYS upload:*
> GET upload:1:chunk:0
```

## Step 9: Monitoring Setup

### Create Monitoring Script
```bash
nano /usr/local/bin/monitor-imports.sh
```

```bash
#!/bin/bash

echo "=== Queue Status ==="
php /path/to/warehouseops/artisan queue:monitor redis:imports

echo -e "\n=== Recent Uploads ==="
psql -d warehouseops -c "
SELECT 
  id, 
  status, 
  total_chunks, 
  processed_chunks,
  EXTRACT(EPOCH FROM (completed_at - created_at)) as duration_seconds
FROM uploads 
WHERE type = 'waybill' 
ORDER BY id DESC 
LIMIT 10;"

echo -e "\n=== Failed Chunks ==="
psql -d warehouseops -c "
SELECT 
  upload_id, 
  chunk_number, 
  status, 
  errors 
FROM import_chunks 
WHERE status = 'failed' 
ORDER BY id DESC 
LIMIT 5;"

echo -e "\n=== Redis Memory ==="
redis-cli INFO memory | grep used_memory_human

echo -e "\n=== Worker Status ==="
sudo supervisorctl status warehouseops-import-worker:*
```

```bash
chmod +x /usr/local/bin/monitor-imports.sh
```

### Add to Cron
```bash
crontab -e
```

```cron
*/5 * * * * /usr/local/bin/monitor-imports.sh >> /var/log/warehouseops/import-monitor.log 2>&1
```

## Step 10: Performance Tuning

### PostgreSQL
```sql
-- Increase work_mem for large upserts
ALTER SYSTEM SET work_mem = '256MB';

-- Increase maintenance_work_mem
ALTER SYSTEM SET maintenance_work_mem = '512MB';

-- Reload config
SELECT pg_reload_conf();
```

### PHP
```ini
; php.ini
memory_limit = 512M
max_execution_time = 600
upload_max_filesize = 100M
post_max_size = 100M
```

### Nginx
```nginx
# nginx.conf
client_max_body_size 100M;
client_body_timeout 300s;
proxy_read_timeout 300s;
```

## Rollback Plan

If issues occur:

### 1. Stop New Uploads
```bash
# Disable upload endpoint temporarily
php artisan down --message="Import system maintenance"
```

### 2. Finish Pending Imports
```bash
# Wait for queue to drain
php artisan queue:monitor redis:imports

# Or force stop
sudo supervisorctl stop warehouseops-import-worker:*
```

### 3. Rollback Database
```bash
php artisan migrate:rollback --step=1
```

### 4. Revert Code
```bash
git revert <commit-hash>
git push
```

### 5. Restart Old System
```bash
php artisan up
sudo supervisorctl start warehouseops-import-worker:*
```

## Troubleshooting

### Issue: Workers not processing jobs
```bash
# Check worker status
sudo supervisorctl status

# Check logs
tail -f /var/log/warehouseops/import-worker.log

# Restart workers
sudo supervisorctl restart warehouseops-import-worker:*
```

### Issue: Redis out of memory
```bash
# Check memory
redis-cli INFO memory

# Clear old chunks (if TTL not working)
redis-cli --scan --pattern "upload:*:chunk:*" | xargs redis-cli DEL

# Increase maxmemory
redis-cli CONFIG SET maxmemory 4gb
```

### Issue: Chunks stuck in processing
```sql
-- Reset stuck chunks
UPDATE import_chunks 
SET status = 'pending', started_at = NULL 
WHERE status = 'processing' 
AND started_at < NOW() - INTERVAL '2 hours';

-- Redispatch
SELECT upload_id, chunk_number 
FROM import_chunks 
WHERE status = 'pending';
-- Manually dispatch: ImportWaybillChunk::dispatch($uploadId, $chunkNumber);
```

### Issue: High database load
```sql
-- Check active queries
SELECT pid, query, state, query_start 
FROM pg_stat_activity 
WHERE state = 'active';

-- Kill long-running queries
SELECT pg_terminate_backend(pid) 
FROM pg_stat_activity 
WHERE state = 'active' 
AND query_start < NOW() - INTERVAL '10 minutes';
```

## Health Checks

Add to monitoring:

```bash
# Queue health
php artisan queue:monitor redis:imports --max=100

# Database connections
psql -d warehouseops -c "SELECT count(*) FROM pg_stat_activity WHERE state = 'active';"

# Redis connections
redis-cli CLIENT LIST | wc -l

# Disk space
df -h | grep /var/lib/postgresql
```

## Success Criteria

✅ Workers processing jobs  
✅ Uploads completing successfully  
✅ No stuck chunks after 1 hour  
✅ Redis memory < 80%  
✅ Database CPU < 70%  
✅ Queue depth < 100  
✅ Average import time < 20 minutes for 500k rows  

## Support

For issues, check:
1. `/var/log/warehouseops/import-worker.log`
2. `/var/log/warehouseops/laravel.log`
3. PostgreSQL logs: `/var/log/postgresql/`
4. Redis logs: `/var/log/redis/`
