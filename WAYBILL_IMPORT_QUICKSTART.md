# Waybill Import - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Run Migration (30 seconds)

```bash
cd /home/it-admin/Downloads/automation/ai-system/projects/warehouseops-v5-main
php artisan migrate
```

Expected output:
```
Migrating: 2026_06_05_000001_add_pipeline_architecture_to_uploads
Migrated:  2026_06_05_000001_add_pipeline_architecture_to_uploads (123.45ms)
```

### Step 2: Start Queue Worker (1 minute)

```bash
# Terminal 1: Start worker
php artisan queue:work redis --queue=imports --tries=3 --timeout=600 --verbose

# Keep this terminal open and running
```

Expected output:
```
[2026-06-05 11:30:00][1] Processing: App\Jobs\ValidateWaybillFile
[2026-06-05 11:30:05][1] Processed:  App\Jobs\ValidateWaybillFile
```

### Step 3: Test Upload (2 minutes)

#### Option A: Using Browser
1. Navigate to: `https://warehouseops.thirdynals.org/waybills/import`
2. Select courier: **J&T** or **Flash**
3. Upload a test Excel file
4. Click "Upload & Validate"
5. Watch progress in real-time

#### Option B: Using cURL
```bash
curl -X POST https://warehouseops.thirdynals.org/waybills/import \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-CSRF-TOKEN: YOUR_CSRF_TOKEN" \
  -F "file=@/path/to/test.xlsx" \
  -F "courier=jnt"
```

### Step 4: Monitor Progress (1 minute)

#### Terminal 2: Watch Database
```bash
watch -n 2 'psql -d warehouseops -c "
SELECT 
  id, 
  status, 
  total_rows,
  processed_chunks || '/' || total_chunks as chunks,
  inserted_rows,
  updated_rows
FROM uploads 
WHERE type = '\''waybill'\'' 
ORDER BY id DESC 
LIMIT 5"'
```

#### Terminal 3: Watch Redis
```bash
# Check chunk data
redis-cli KEYS "upload:*:chunk:*"

# Monitor memory
watch -n 2 'redis-cli INFO memory | grep used_memory_human'
```

## 📊 What to Expect

### Timeline for 100k Row File

```
00:00 - Upload started (status: PENDING)
00:05 - Validation complete (status: VALIDATED)
00:10 - Chunking complete (status: READY_TO_IMPORT)
00:15 - Import started (status: IMPORTING)
      - Chunk 0/10 processed
      - Chunk 1/10 processed
      - ... (parallel processing)
05:00 - Import complete (status: COMPLETED)
```

### Status Flow

```
PENDING
  ↓ (5 seconds)
VALIDATING
  ↓ (10 seconds)
VALIDATED
  ↓ (30 seconds)
TRANSFORMING
  ↓ (1 minute)
READY_TO_IMPORT
  ↓ (automatic)
IMPORTING
  ↓ (3-5 minutes for 100k rows)
COMPLETED ✅
```

## 🔍 Troubleshooting

### Issue: Worker not processing

**Check:**
```bash
# Is worker running?
ps aux | grep "queue:work"

# Check queue
php artisan queue:monitor redis:imports
```

**Fix:**
```bash
# Restart worker
php artisan queue:restart
php artisan queue:work redis --queue=imports --tries=3 --timeout=600
```

### Issue: Upload stuck in VALIDATING

**Check:**
```bash
# Check failed jobs
php artisan queue:failed

# Check logs
tail -f storage/logs/laravel.log
```

**Fix:**
```bash
# Retry failed job
php artisan queue:retry all
```

### Issue: Redis connection error

**Check:**
```bash
# Test Redis
redis-cli ping
# Should return: PONG

# Check Laravel connection
php artisan tinker
>>> Redis::ping()
```

**Fix:**
```bash
# Start Redis
sudo systemctl start redis

# Update .env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## 📝 Test Data

### Create Test Excel File

**J&T Format:**
```
Waybill Number | Order Status | Receiver | Receiver Cellphone | Province | City | Barangay | Address | COD
JT1234567890   | Delivered    | John Doe | 09171234567        | Metro Manila | Manila | Ermita | 123 Main St | 1000
JT1234567891   | In Transit   | Jane Doe | 09181234567        | Cebu     | Cebu City | Lahug | 456 Oak Ave | 2000
```

**Flash Format:**
```
Tracking No. | Status    | Consignee Name | Consignee Phone | Province | City | Barangay | Address | COD Amount
FL1234567890 | Delivered | John Doe       | 09171234567     | Metro Manila | Manila | Ermita | 123 Main St | 1000
FL1234567891 | Pending   | Jane Doe       | 09181234567     | Cebu     | Cebu City | Lahug | 456 Oak Ave | 2000
```

### Download Template

```bash
# From browser
https://warehouseops.thirdynals.org/waybills/import/template?courier=jnt

# Or using cURL
curl -O https://warehouseops.thirdynals.org/waybills/import/template?courier=jnt
```

## 🎯 Success Checklist

After upload:

- [ ] Upload record created in database
- [ ] Status changes: PENDING → VALIDATING → VALIDATED
- [ ] Validation metadata stored (columns, sample rows)
- [ ] Chunks created in `import_chunks` table
- [ ] Chunks processed in parallel
- [ ] Waybills inserted/updated in `waybills` table
- [ ] Final status: COMPLETED
- [ ] Redis chunks cleaned up

## 📈 Performance Benchmarks

### Expected Processing Times

| File Size | Rows | Chunks | Time | Workers |
|-----------|------|--------|------|---------|
| 1 MB | 1,000 | 1 | 10s | 1 |
| 10 MB | 10,000 | 1 | 30s | 1 |
| 50 MB | 100,000 | 10 | 5m | 4 |
| 100 MB | 500,000 | 50 | 15m | 4 |

### Optimization Tips

**Faster Processing:**
```bash
# Increase workers
php artisan queue:work redis --queue=imports --tries=3 --timeout=600 &
php artisan queue:work redis --queue=imports --tries=3 --timeout=600 &
php artisan queue:work redis --queue=imports --tries=3 --timeout=600 &
php artisan queue:work redis --queue=imports --tries=3 --timeout=600 &
```

**Reduce Memory:**
```php
// In TransformWaybillFile.php
protected int $chunkSize = 5000; // Reduce from 10000
```

**Increase Throughput:**
```php
// In ImportWaybillChunk.php
protected int $batchSize = 2000; // Increase from 1500 (if DB can handle it)
```

## 🔗 Next Steps

1. **Read Full Documentation**
   - `docs/WAYBILL_IMPORT_ARCHITECTURE.md` - System design
   - `docs/WAYBILL_IMPORT_DEPLOYMENT.md` - Production setup
   - `docs/WAYBILL_IMPORT_SUMMARY.md` - Implementation details

2. **Set Up Monitoring**
   - Configure Supervisor for auto-restart
   - Set up log rotation
   - Add health check endpoints

3. **Enable Broadcasting** (Optional)
   - Install Laravel Echo Server or Pusher
   - Update frontend for real-time updates
   - Test WebSocket connections

4. **Production Deployment**
   - Follow deployment guide
   - Configure multiple workers
   - Set up monitoring and alerts

## 💡 Pro Tips

1. **Always run worker in verbose mode during testing:**
   ```bash
   php artisan queue:work redis --queue=imports --verbose
   ```

2. **Monitor Redis memory:**
   ```bash
   watch -n 5 'redis-cli INFO memory | grep used_memory_human'
   ```

3. **Check queue depth:**
   ```bash
   php artisan queue:monitor redis:imports
   ```

4. **Clear stuck jobs:**
   ```bash
   php artisan queue:flush
   ```

5. **Retry failed jobs:**
   ```bash
   php artisan queue:retry all
   ```

## ✅ You're Ready!

The new waybill import system is now set up and ready to use. Start with a small test file and gradually increase to larger files as you gain confidence.

**Happy Importing! 🎉**
