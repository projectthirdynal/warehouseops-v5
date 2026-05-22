# Waybill Import System Refactor — Design Spec
Date: 2026-05-10
Approach: C (Hybrid — enhance existing architecture, no staging table)

---

## Problem

The current import system works for basic file uploads but is missing production-critical features:
- No courier or import_type stored on the upload record
- No inserted/updated/skipped breakdown — only success/error
- No file validation before queuing — errors only surface after the job runs
- No preview before processing
- No downloadable error report
- Cancel deletes all imported waybills (destructive, non-recoverable)
- Status machine only has 4 values; completed-with-errors is indistinguishable from completed

---

## Architecture Decision: No Staging Table

The existing `ON CONFLICT (waybill_number) DO UPDATE SET ... WHERE IS DISTINCT FROM` already provides safe upsert behavior:
- Static fields (name, address) are written once on INSERT, never overwritten
- Only status-related fields update on conflict
- Rows that haven't changed are skipped at the DB level

A staging table would add complexity without improving data safety. This decision is revisited in Phase 4 (Advanced Features) of the design doc roadmap.

---

## Status State Machine

```
queued → validating → validation_failed     (stops here if headers/format invalid)
                    → ready_to_process      (validation passed, waiting for user to start)
                    → processing → completed              (all rows succeeded)
                                 → completed_with_errors  (processed but some rows failed)
                                 → failed                 (job threw an unhandled exception)
                                 → cancelled              (user cancelled mid-run)
```

`validation_failed` is a terminal state. User must re-upload a corrected file.
`cancelled` is a terminal state. No waybill deletion — already-imported batches stay.

---

## Data Model Changes

### `uploads` table — new columns

| Column | Type | Purpose |
|---|---|---|
| `courier` | string nullable | 'jnt' or 'flash' — stored at upload time |
| `import_type` | string nullable | 'new_waybill', 'status_update', 'cod_update', 'full_update' |
| `inserted_rows` | integer default 0 | Rows inserted as new waybills |
| `updated_rows` | integer default 0 | Rows that updated an existing waybill's status |
| `skipped_rows` | integer default 0 | Rows skipped because data was unchanged |
| `file_hash` | string nullable | SHA-256 of uploaded file for duplicate detection |
| `started_at` | timestamp nullable | When job started processing |
| `completed_at` | timestamp nullable | When job finished (any terminal state) |

`error_rows` is kept as-is (rows that threw exceptions during parse/upsert).
`success_rows` is kept but now equals `inserted_rows + updated_rows + skipped_rows`.

### `Upload` model — new constants and fillables

New status constants: `STATUS_QUEUED`, `STATUS_VALIDATING`, `STATUS_VALIDATION_FAILED`, `STATUS_COMPLETED_WITH_ERRORS`, `STATUS_CANCELLED`

New method: `markAsCompletedWithErrors(array $errors)`, `markAsValidationFailed(array $errors)`, `markAsCancelled()`

---

## Validation Endpoint

`POST /waybills/import/{upload}/validate`

Called immediately after file upload (before dispatching the background job). Runs synchronously since it only reads the first ~200 rows.

Checks:
- Required headers present for the given courier
- Waybill number column not empty in sampled rows
- No completely blank file
- Detects obvious duplicate waybill numbers in the first pass

Returns:
```json
{
  "valid": true,
  "total_rows_detected": 46669,
  "missing_headers": [],
  "sample_rows": [ ... first 20 rows mapped to internal fields ... ],
  "detected_columns": [ "waybill_number", "status", "receiver_name", ... ],
  "duplicate_waybills_in_file": 3,
  "warnings": []
}
```

On failure (`valid: false`): upload status set to `validation_failed`, job is NOT dispatched.

---

## Preview Endpoint

`GET /waybills/import/{upload}/preview`

Returns the `sample_rows` and `detected_columns` from the validation result (stored on the upload record as JSON). No re-processing of file.

---

## Error Report Download

`GET /waybills/import/{upload}/errors/download`

Generates a CSV of failed rows using the errors JSON stored on the upload. Columns: Row, Waybill Number, Error Reason.

Uses PHP's built-in `fputcsv` streamed through a `StreamedResponse` — NOT PhpSpreadsheet (too slow) and League\Csv is not installed. If the error list is empty, returns 404.

---

## Importer Tracking Changes

Both `JntWaybillFastImport` and `FlashWaybillFastImport` must track:
- `$insertedCount` — rows where `ON CONFLICT` inserted a new row
- `$updatedCount` — rows where `ON CONFLICT` updated an existing row
- `$skippedCount` — rows where `IS DISTINCT FROM` condition was false (no write)

PostgreSQL `GET DIAGNOSTICS` / `xmax` approach: use `RETURNING xmax` on the upsert to detect insert vs update vs skip.

```sql
INSERT INTO waybills (...) VALUES (...)
ON CONFLICT (waybill_number) DO UPDATE SET ...
WHERE (waybills.status, ...) IS DISTINCT FROM (EXCLUDED.status, ...)
RETURNING xmax, (xmax = 0) AS is_insert,
          (xmax != 0 AND ...) AS is_update
```

Rows not returned were skipped (no write occurred due to `WHERE IS DISTINCT FROM` being false).

Progress update per batch also writes `inserted_rows`, `updated_rows`, `skipped_rows` incrementally.

---

## Store Flow Change

Current: `store()` saves file → creates Upload → dispatches job

New: `store()` saves file → creates Upload (status=`queued`, with courier + import_type + file_hash) → returns upload ID to frontend → frontend calls `validate` → on validation pass, frontend calls `start` to dispatch job

Routes:
- `POST /waybills/import` — save file, create Upload (queued), return upload id
- `POST /waybills/import/{upload}/validate` — run validation, update status, return result
- `POST /waybills/import/{upload}/start` — dispatch ProcessWaybillImport job (only if status=ready_to_process)
- `GET /waybills/import/{upload}/preview` — return stored preview data
- `GET /waybills/import/{upload}/errors/download` — download error CSV

---

## Cancel Behavior Change

Current: cancel sets status=cancelled AND deletes all waybills from that upload.

New: cancel sets status=cancelled only. No waybill deletion. Already-imported batches remain in the waybills table (they are valid data). The worker checks cancellation every 10 batches and stops flushing new batches.

---

## Frontend Changes

### Import.tsx
- Add `import_type` select (New Waybill Import / Status Update / COD Update / Full Update)
- Upload flow: upload file → trigger validate → show validation result + preview table → "Start Import" button
- Upload history: show `courier`, `import_type`, inserted/updated/skipped/failed counts
- Use `completed_with_errors` status badge (orange/amber)

### ImportDetail.tsx
- Stats row: Total / Inserted / Updated / Skipped / Failed
- "Download Error Report" button (only shown when `error_rows > 0`)
- Show `courier` and `import_type` in header metadata

---

## Files Changed

| File | Change Type |
|---|---|
| `database/migrations/2026_05_10_add_import_fields_to_uploads.php` | New |
| `app/Models/Upload.php` | Modify |
| `app/Imports/JntWaybillFastImport.php` | Modify |
| `app/Imports/FlashWaybillFastImport.php` | Modify |
| `app/Http/Controllers/WaybillImportController.php` | Modify |
| `routes/web.php` | Modify |
| `resources/js/pages/Waybills/Import.tsx` | Modify |
| `resources/js/pages/Waybills/ImportDetail.tsx` | Modify |

---

## Implementation Order

1. Database migration
2. Upload model (constants, fillables, methods)
3. JntWaybillFastImport (RETURNING xmax tracking)
4. FlashWaybillFastImport (same)
5. WaybillImportController (new endpoints, store/validate/start flow)
6. routes/web.php (new routes)
7. Import.tsx (import_type, validation UI, improved history)
8. ImportDetail.tsx (breakdown stats, error download)

---

## Testing Checklist

- [ ] Migration runs cleanly on existing data (no column conflicts)
- [ ] Upload with valid JNT file: queued → validating → ready_to_process → processing → completed
- [ ] Upload with valid Flash file: same flow
- [ ] Upload with missing required header: status = validation_failed, no job dispatched
- [ ] Upload with all-unchanged rows: skipped_rows matches total, updated_rows = 0
- [ ] Upload with mix of new + existing + unchanged rows: all three counters correct
- [ ] Error rows: failed rows captured, download CSV returns correct data
- [ ] Completed with errors: status shows completed_with_errors when error_rows > 0
- [ ] Cancel mid-import: status = cancelled, no waybill deletion, worker stops cleanly
- [ ] Duplicate file upload: file_hash match shows warning (frontend only, not blocked)
- [ ] Import detail page: all 5 stat cards correct, error download visible when errors > 0
- [ ] Upload history: courier and import_type visible per row
