# Waybill Import Architecture

## Overview

The waybill import system uses a **multi-stage pipeline architecture** that separates concerns and enables parallel processing for scalability and reliability.

## Architecture Diagram

```
┌─────────────┐    ┌──────────────┐    ┌─────────────┐    ┌──────────────┐
│   Upload    │───▶│  Validation  │───▶│  Transform  │───▶│   Import     │
│   Stage     │    │    Stage     │    │    Stage    │    │   Stage      │
└─────────────┘    └──────────────┘    └─────────────┘    └──────────────┘
      │                   │                    │                   │
      ▼                   ▼                    ▼                   ▼
  Storage            Queue Job           Queue Job          Queue Jobs
  + Metadata      (Fast, Async)       (Chunking)         (Parallel)
```

## Pipeline Stages

### Stage 1: Upload & Storage
**Controller:** `WaybillImportController@store`  
**Status:** `PENDING` → `QUEUED`

- Validates file metadata (size, type, MIME)
- Stores file to disk/S3 with UUID
- Creates upload record
- Dispatches validation job

**Key Features:**
- Unique file identification with UUID
- SHA-256 hash for duplicate detection
- Immediate response to user

### Stage 2: Validation
**Job:** `ValidateWaybillFile`  
**Service:** `WaybillFileValidator`  
**Status:** `VALIDATING` → `VALIDATED` or `VALIDATION_FAILED`

- Streams first 1000 rows for validation
- Checks required headers
- Detects duplicates
- Collects sample data
- Stores metadata for preview

**Key Features:**
- Streaming validation (memory efficient)
- Configurable sample size
- Detailed error reporting
- Auto-start option

### Stage 3: Transform & Chunking
**Job:** `TransformWaybillFile`  
**Status:** `TRANSFORMING` → `READY_TO_IMPORT`

- Streams entire file
- Normalizes data (snake_case columns)
- Splits into chunks (10k rows each)
- Stores chunks in Redis (2hr TTL)
- Creates chunk records in database
- Dispatches import jobs

**Key Features:**
- Configurable chunk size
- Redis-based temporary storage
- Cancellation support
- Progress tracking

### Stage 4: Import (Parallel)
**Job:** `ImportWaybillChunk`  
**Status:** `IMPORTING` → `COMPLETED`

- Processes chunks in parallel
- Batch upserts (1500 rows per batch)
- Atomic progress updates
- Automatic retry on failure
- Cleanup after completion

**Key Features:**
- Parallel processing (multiple workers)
- Idempotent operations
- Dynamic batch sizing
- Insert/update tracking

## Database Schema

### uploads Table (Enhanced)
```sql
- uuid: UUID (unique identifier)
- file_path: VARCHAR (storage path)
- total_chunks: INT (number of chunks)
- processed_chunks: INT (completed chunks)
- metadata: JSON (validation results, samples)
- auto_import: BOOLEAN (auto-start after validation)
```

### import_chunks Table (New)
```sql
- upload_id: BIGINT (foreign key)
- chunk_number: INT (0-indexed)
- status: VARCHAR (pending/processing/completed/failed)
- rows_count: INT (rows in chunk)
- inserted_count: INT (new waybills)
- updated_count: INT (existing waybills)
- error_count: INT (failed rows)
- errors: JSON (error details)
- started_at: TIMESTAMP
- completed_at: TIMESTAMP
```

## Status Flow

```
PENDING
  ↓
QUEUED (validation dispatched)
  ↓
VALIDATING
  ├─→ VALIDATION_FAILED (errors found)
  └─→ VALIDATED
        ↓
      TRANSFORMING (chunking)
        ↓
      READY_TO_IMPORT
        ↓
      IMPORTING (chunks processing)
        ├─→ COMPLETED_WITH_ERRORS (some chunks failed)
        └─→ COMPLETED (all chunks succeeded)
```

## Configuration

### Queue Configuration
```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 7200, // 2 hours
        'block_for' => null,
    ],
],
```

### Import Settings
```php
// Chunk size (rows per chunk)
protected int $chunkSize = 10000;

// Batch size (rows per upsert)
protected int $batchSize = 1500;

// Redis TTL (seconds)
protected int $redisTTL = 7200;

// Max validation rows
protected int $maxValidationRows = 1000;
```

## Real-Time Updates

### Broadcasting Events
```php
WaybillImportStarted
WaybillValidationCompleted
WaybillChunkProcessed
WaybillImportCompleted
WaybillImportFailed
```

### Frontend Subscription
```typescript
// Listen to upload channel
Echo.channel(`upload.${uploadId}`)
  .listen('.validation.completed', (e) => {
    // Update UI with validation results
  })
  .listen('.chunk.processed', (e) => {
    // Update progress bar
  })
  .listen('.import.completed', (e) => {
    // Show success message
  });
```

## Error Handling

### Retry Strategy
- **Validation:** 3 retries with exponential backoff
- **Transform:** 2 retries (file issues unlikely to resolve)
- **Import Chunk:** 3 retries (transient DB issues)

### Failure Recovery
- Failed chunks can be retried independently
- Partial imports are preserved
- Detailed error logs per chunk
- User can cancel at any stage

## Performance Characteristics

### Scalability
- **Horizontal:** Add more queue workers
- **Vertical:** Increase chunk/batch sizes
- **Parallel:** Multiple chunks processed simultaneously

### Throughput
- **Small files (<10k rows):** ~30 seconds
- **Medium files (100k rows):** ~5 minutes
- **Large files (500k rows):** ~15 minutes

### Resource Usage
- **Memory:** ~256MB per worker
- **Redis:** ~50MB per 100k rows (temporary)
- **Database:** Optimized upserts with minimal locks

## Monitoring

### Key Metrics
```sql
-- Upload success rate
SELECT 
  status,
  COUNT(*) as count,
  AVG(EXTRACT(EPOCH FROM (completed_at - created_at))) as avg_duration_seconds
FROM uploads
WHERE type = 'waybill'
GROUP BY status;

-- Chunk processing stats
SELECT 
  upload_id,
  COUNT(*) as total_chunks,
  SUM(inserted_count) as total_inserted,
  SUM(updated_count) as total_updated,
  SUM(error_count) as total_errors
FROM import_chunks
GROUP BY upload_id;
```

### Health Checks
- Queue depth monitoring
- Failed job alerts
- Redis memory usage
- Average processing time

## Migration Guide

### From Old System
1. Run migration: `php artisan migrate`
2. Update `.env` with Redis configuration
3. Configure queue workers
4. Enable broadcasting (optional)
5. Deploy new code
6. Monitor first few imports

### Rollback Plan
- Old import jobs still functional
- Can process uploads created before migration
- Database schema is backward compatible

## Troubleshooting

### Common Issues

**Chunks stuck in processing:**
```bash
# Check Redis for orphaned chunks
redis-cli KEYS "upload:*:chunk:*"

# Reset stuck chunks
UPDATE import_chunks 
SET status = 'pending', started_at = NULL 
WHERE status = 'processing' 
AND started_at < NOW() - INTERVAL '1 hour';
```

**High memory usage:**
```php
// Reduce chunk size
protected int $chunkSize = 5000;

// Reduce batch size
protected int $batchSize = 1000;
```

**Slow imports:**
```bash
# Increase queue workers
php artisan queue:work redis --queue=imports --tries=3 --timeout=600 --sleep=3 --max-jobs=100

# Run multiple workers in parallel
supervisor:
[program:warehouseops-import-worker]
process_name=%(program_name)s_%(process_num)02d
command=php artisan queue:work redis --queue=imports
numprocs=4
```

## Best Practices

1. **Monitor queue depth** - Scale workers based on load
2. **Set Redis maxmemory** - Prevent OOM with eviction policy
3. **Use supervisor** - Auto-restart failed workers
4. **Enable logging** - Track import metrics
5. **Test with production data** - Validate performance at scale

## Future Enhancements

- [ ] S3 storage for large files
- [ ] Compression for Redis chunks
- [ ] ML-based duplicate detection
- [ ] Auto-retry failed chunks
- [ ] Import scheduling
- [ ] Data quality scoring
- [ ] Export functionality
