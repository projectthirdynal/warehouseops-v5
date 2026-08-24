<?php

namespace App\Services;

use App\Domain\Courier\Services\StatusMapper;
use App\Jobs\GenerateLeadsFromUpload;
use App\Models\GoogleSheetSync;
use App\Models\Upload;
use App\Models\WaybillTrackingHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;

class GoogleSheetSyncService
{
    protected ?StatusMapper $statusMapper = null;

    /**
     * Fetch the Google Sheet as CSV, save to temp file, and process it
     * using the existing FastExcel-based import pipeline.
     *
     * @return array{rows: int, inserted: int, updated: int, skipped: int, errors: int, message: string}
     */
    public function sync(GoogleSheetSync $sync, int $userId): array
    {
        $this->statusMapper = app(StatusMapper::class);

        try {
            $csvUrl = $sync->getCsvExportUrl();
        } catch (\InvalidArgumentException $e) {
            // Defense-in-depth: controller already validates the host, but stale rows
            // or direct DB edits could otherwise make the server fetch an arbitrary URL.
            return ['rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'message' => $e->getMessage()];
        }

        Log::info("GoogleSheetSync: fetching CSV from {$csvUrl} for sync #{$sync->id}");

        $response = Http::timeout(60)->get($csvUrl);

        if (! $response->successful()) {
            $msg = "Failed to fetch Google Sheet: HTTP {$response->status()}";

            return ['rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'message' => $msg];
        }

        $csvContent = $response->body();

        if (empty(trim($csvContent))) {
            return ['rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'message' => 'Google Sheet is empty.'];
        }

        // Save CSV to temp file for FastExcel streaming reader
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir.'/gs_sync_'.$sync->id.'_'.time().'.csv';
        file_put_contents($tempPath, $csvContent);

        try {
            // Create an Upload record to track this sync (reuses existing waybill tracking infrastructure)
            $upload = Upload::create([
                'filename' => basename($tempPath),
                'original_filename' => $sync->name.' (Google Sheet sync)',
                'type' => 'waybill',
                'courier' => $sync->courier,
                'import_type' => 'google_sheet_sync',
                'file_hash' => hash('sha256', $csvContent),
                'status' => Upload::STATUS_PROCESSING,
                'uploaded_by' => $userId,
                'started_at' => now(),
            ]);

            $result = $this->processCsv($tempPath, $sync, $upload, $userId);

            $upload->update([
                'status' => $result['errors'] > 0
                    ? Upload::STATUS_COMPLETED_WITH_ERRORS
                    : Upload::STATUS_COMPLETED,
                'total_rows' => $result['rows'],
                'processed_rows' => $result['rows'],
                'success_rows' => $result['inserted'] + $result['updated'] + $result['skipped'],
                'inserted_rows' => $result['inserted'],
                'updated_rows' => $result['updated'],
                'skipped_rows' => $result['skipped'],
                'error_rows' => $result['errors'],
                'completed_at' => now(),
            ]);

            // Generate leads from delivered waybills (same as file import pipeline)
            GenerateLeadsFromUpload::dispatch($upload->id);

            return $result;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Process the CSV file: parse rows, map columns, bulk upsert waybills.
     * Uses the same column mapping and upsert logic as JntWaybillFastImport / FlashWaybillFastImport.
     *
     * @return array{rows: int, inserted: int, updated: int, skipped: int, errors: int, message: string}
     */
    protected function processCsv(string $filePath, GoogleSheetSync $sync, Upload $upload, int $userId): array
    {
        $courier = $sync->courier;
        $isJnt = $courier === 'jnt';

        // Column maps — same as the fast import classes, but also accept generic header names
        // that are common in Google Sheet layouts (lowercase, spaces replaced with underscores).
        $columnMap = $isJnt ? [
            'waybill_number' => ['waybill_number', 'waybill number', 'Waybill Number', 'tracking_no', 'tracking no', 'Tracking No.'],
            'creator_code' => ['creator_code', 'creator code', 'Creator Code'],
            'status' => ['order_status', 'order status', 'Order Status', 'status', 'Status'],
            'sign_for_pictures' => ['sign_for_pictures', 'sign for pictures', 'Sign For Pictures'],
            'signed_at' => ['signingtime', 'SigningTime', 'signed_at', 'Signed At'],
            'receiver_name' => ['receiver', 'Receiver', 'receiver_name', 'Receiver Name', 'consignee', 'Consignee'],
            'receiver_phone' => ['receiver_cellphone', 'Receiver Cellphone', 'receiver_phone', 'Receiver Phone', 'consignee_phone', 'Consignee phone'],
            'state' => ['province', 'Province', 'state', 'State'],
            'city' => ['city', 'City'],
            'barangay' => ['barangay', 'Barangay'],
            'receiver_address' => ['address', 'Address', 'receiver_address', 'Receiver Address', 'consignee_address', 'Consignee address'],
            'payment_method' => ['payment_method', 'Payment Method'],
            'settlement_weight' => ['settlement_weight', 'Settlement Weight', 'weight', 'Weight'],
            'shipping_cost' => ['total_shipping_cost', 'Total Shipping Cost', 'shipping_cost', 'Shipping Cost', 'shipping_fee', 'Shipping Fee'],
            'cod_amount' => ['cod', 'Cod', 'cod_amount', 'COD Amount', 'cod_amt'],
            'submitted_at' => ['submission_time', 'Submission Time', 'submitted_at', 'Submitted At', 'pu_time', 'PU time'],
            'rts_reason' => ['rts_reason', 'RTS Reason', 'remark3', 'Remark3'],
            'remarks' => ['remarks', 'Remarks', 'remark2', 'Remark2', 'notes', 'Notes'],
            'express_type' => ['express_type', 'Express Type', 'product_type', 'Product Type'],
            'sender_name' => ['sender_name', 'Sender Name', 'shipping_customer', 'Shipping Customer', 'sender', 'Sender'],
            'sender_phone' => ['sender_cellphone', 'Sender Cellphone', 'sender_phone', 'Sender Phone'],
            'sender_province' => ['sender_province', 'Sender Province'],
            'sender_city' => ['sender_city', 'Sender City'],
            'item_name' => ['item_name', 'Item Name', 'remark1', 'Remark1', 'product_name', 'Product Name'],
            'item_qty' => ['number_of_items', 'Number Of Items', 'item_qty', 'Quantity', 'qty'],
            'item_value' => ['item_value', 'Item Value', 'declared_value', 'Declared value'],
            'valuation_fee' => ['valuation_fee', 'Valuation Fee'],
        ] : [
            'waybill_number' => ['Tracking No.', 'tracking_no', 'Tracking No', 'PNO', 'waybill_number', 'Waybill Number'],
            'status' => ['Status', 'status', 'order_status', 'Order Status'],
            'receiver_name' => ['Consignee', 'consignee', 'Consignee Name', 'receiver_name', 'Receiver Name'],
            'receiver_phone' => ['Consignee phone', 'consignee_phone', 'Consignee Phone', 'receiver_phone', 'Receiver Phone'],
            'receiver_address' => ['Consignee address', 'consignee_address', 'Consignee Address', 'receiver_address', 'Address'],
            'sender_name' => ['Sender', 'sender', 'Sender Name', 'sender_name'],
            'sender_phone' => ['Sender phone', 'sender_phone', 'Sender Phone'],
            'item_name' => ['Remark1', 'remark1', 'Item type', 'item_name', 'Item Name', 'product_name', 'Product Name'],
            'remarks' => ['Remark2', 'remark2', 'remarks', 'Remarks', 'notes', 'Notes'],
            'rts_reason' => ['Remark3', 'remark3', 'rts_reason', 'RTS Reason'],
            'settlement_weight' => ['Chargeable Weight', 'chargeable_weight', 'settlement_weight', 'Weight', 'weight'],
            'shipping_cost' => ['Standard Shipping Fee', 'Total charge', 'total_charge', 'shipping_cost', 'Shipping Cost', 'shipping_fee'],
            'cod_amount' => ['COD Amt', 'cod_amt', 'COD Amount', 'cod_amount', 'cod', 'Cod'],
            'submitted_at' => ['PU time', 'pu_time', 'Pickup Time', 'submitted_at', 'Submitted At'],
            'delivered_at' => ['Delivery time', 'delivery_time', 'delivered_at', 'Delivered At'],
            'express_type' => ['Product Type', 'product_type', 'express_type', 'Express Type'],
        ];

        $allColumns = $isJnt ? [
            'waybill_number', 'creator_code', 'status', 'sign_for_pictures', 'signed_at',
            'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
            'payment_method', 'settlement_weight', 'shipping_cost', 'cod_amount',
            'submitted_at', 'rts_reason', 'remarks', 'express_type',
            'sender_name', 'sender_phone', 'sender_province', 'sender_city',
            'item_name', 'item_qty', 'item_value', 'valuation_fee',
            'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
            'created_at', 'updated_at',
        ] : [
            'waybill_number', 'creator_code', 'status',
            'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
            'settlement_weight', 'shipping_cost', 'cod_amount', 'amount',
            'submitted_at', 'rts_reason', 'remarks', 'express_type',
            'sender_name', 'sender_phone', 'sender_province', 'sender_city',
            'item_name', 'item_qty', 'item_value',
            'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
            'created_at', 'updated_at',
        ];

        $statusFields = $isJnt ? [
            'status', 'signed_at', 'delivered_at', 'returned_at', 'rts_reason', 'remarks',
            'shipping_cost', 'cod_amount', 'settlement_weight', 'upload_id', 'uploaded_by', 'updated_at',
        ] : [
            'status', 'delivered_at', 'returned_at', 'rts_reason', 'remarks',
            'shipping_cost', 'cod_amount', 'settlement_weight', 'amount', 'upload_id', 'uploaded_by', 'updated_at',
        ];

        $columnCount = count($allColumns);
        $batchSize = (int) floor(65000 / $columnCount);

        $batch = [];
        $rowNumber = 0;
        $now = now()->toDateTimeString();
        $errors = [];
        $errorCount = 0;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $batchCount = 0;

        DB::statement('SET synchronous_commit = OFF');

        try {
            (new FastExcel)->import($filePath, function ($row) use (
                &$batch, &$rowNumber, $now, $columnMap, $isJnt,
                &$errors, &$errorCount, &$inserted, &$updated, &$skipped,
                &$batchCount, $batchSize, $allColumns, $statusFields, $upload, $userId
            ) {
                $rowNumber++;

                // Skip empty rows
                if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                    return;
                }

                try {
                    $data = $this->mapRow($row, $now, $columnMap, $isJnt, $upload, $userId);
                    if ($data) {
                        $batch[] = $data;
                    }
                } catch (\Throwable $e) {
                    if (count($errors) < 1000) {
                        $errors[] = ['row' => $rowNumber, 'error' => $e->getMessage()];
                    }
                    $errorCount++;

                    return;
                }

                if (count($batch) >= $batchSize) {
                    $batchCount++;
                    $flushed = count($batch);
                    $currentBatch = $batch;
                    $batch = [];

                    try {
                        $counts = $this->bulkUpsert($currentBatch, $allColumns, $statusFields, $upload);
                        $inserted += $counts['inserted'];
                        $updated += $counts['updated'];
                        $skipped += $counts['skipped'];
                    } catch (\Throwable $e) {
                        if (count($errors) < 1000) {
                            $errors[] = ['row' => $rowNumber, 'error' => $this->sanitizeDbError($e->getMessage())];
                        }
                        $errorCount += $flushed;
                    }
                }
            });

            // Flush remaining batch
            if (! empty($batch)) {
                $flushed = count($batch);
                try {
                    $counts = $this->bulkUpsert($batch, $allColumns, $statusFields, $upload);
                    $inserted += $counts['inserted'];
                    $updated += $counts['updated'];
                    $skipped += $counts['skipped'];
                } catch (\Throwable $e) {
                    if (count($errors) < 1000) {
                        $errors[] = ['row' => 'final_batch', 'error' => $this->sanitizeDbError($e->getMessage())];
                    }
                    $errorCount += $flushed;
                }
            }
        } finally {
            DB::statement('SET synchronous_commit = ON');
        }

        $totalRows = $rowNumber;
        $successCount = $inserted + $updated + $skipped;

        $upload->update([
            'errors' => array_slice($errors, 0, 100),
        ]);

        $message = $errorCount > 0
            ? "Synced {$successCount} waybills ({$inserted} new, {$updated} updated, {$skipped} unchanged) with {$errorCount} errors."
            : "Synced {$successCount} waybills ({$inserted} new, {$updated} updated, {$skipped} unchanged).";

        return [
            'rows' => $totalRows,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errorCount,
            'message' => $message,
        ];
    }

    /**
     * Map a single CSV row to the waybill column structure.
     *
     * @param  array  $columnMap  Field → possible header names
     * @return array<string, mixed>|null Normalized row data or null if invalid
     */
    protected function mapRow(array $row, string $now, array $columnMap, bool $isJnt, Upload $upload, int $userId): ?array
    {
        $data = [];

        foreach ($columnMap as $field => $possibleHeaders) {
            $value = $this->findValue($row, $possibleHeaders);
            if ($value !== null && $value !== '') {
                $data[$field] = $this->transformValue($field, $value);
            }
        }

        if (empty($data['waybill_number'])) {
            throw new \InvalidArgumentException('Missing waybill number.');
        }

        // Ensure required NOT NULL fields have defaults
        $data['receiver_name'] = $data['receiver_name'] ?? 'Unknown';
        $data['receiver_phone'] = $data['receiver_phone'] ?? '';
        $data['receiver_address'] = $data['receiver_address'] ?? '';

        // Map status via StatusMapper
        $courierCode = $isJnt ? 'JNT' : 'FLASH';
        $data['status'] = isset($data['status'])
            ? $this->statusMapper->resolve($courierCode, $data['status'])->value
            : 'PENDING';

        // Set delivered_at / returned_at based on status
        if ($data['status'] === 'DELIVERED') {
            $data['delivered_at'] = $data['delivered_at'] ?? $data['signed_at'] ?? null;
        }
        if ($data['status'] === 'RETURNED') {
            $data['returned_at'] = $data['returned_at'] ?? $data['signed_at'] ?? null;
        }

        // Common fields
        $data['courier_provider'] = $isJnt ? 'J&T' : 'Flash';
        $data['upload_id'] = $upload->id;
        $data['uploaded_by'] = $userId;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        // Normalize to ensure all columns exist
        $allColumns = $isJnt ? [
            'waybill_number', 'creator_code', 'status', 'sign_for_pictures', 'signed_at',
            'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
            'payment_method', 'settlement_weight', 'shipping_cost', 'cod_amount',
            'submitted_at', 'rts_reason', 'remarks', 'express_type',
            'sender_name', 'sender_phone', 'sender_province', 'sender_city',
            'item_name', 'item_qty', 'item_value', 'valuation_fee',
            'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
            'created_at', 'updated_at',
        ] : [
            'waybill_number', 'creator_code', 'status',
            'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
            'settlement_weight', 'shipping_cost', 'cod_amount', 'amount',
            'submitted_at', 'rts_reason', 'remarks', 'express_type',
            'sender_name', 'sender_phone', 'sender_province', 'sender_city',
            'item_name', 'item_qty', 'item_value',
            'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
            'created_at', 'updated_at',
        ];

        $normalized = [];
        foreach ($allColumns as $col) {
            $normalized[$col] = $data[$col] ?? null;
        }

        return $normalized;
    }

    protected function findValue(array $row, array $headers): mixed
    {
        foreach ($headers as $header) {
            if (isset($row[$header]) && $row[$header] !== '') {
                return $row[$header];
            }
        }

        return null;
    }

    protected function transformValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['signed_at', 'submitted_at', 'delivered_at'])) {
            return $this->parseDateTime($value);
        }

        if ($field === 'sign_for_pictures') {
            return strtolower(trim((string) $value)) === 'yes';
        }

        if (in_array($field, ['shipping_cost', 'cod_amount', 'settlement_weight', 'item_value', 'valuation_fee', 'amount'])) {
            return $this->parseNumeric($value);
        }

        if ($field === 'item_qty') {
            return (int) $this->parseNumeric($value) ?: 1;
        }

        if (in_array($field, ['receiver_phone', 'sender_phone'])) {
            return $this->cleanPhone($value);
        }

        return trim((string) $value);
    }

    protected function parseDateTime(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $ts = strtotime((string) $value);

        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    protected function parseNumeric(mixed $value): float
    {
        if (empty($value)) {
            return 0;
        }

        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }

    protected function cleanPhone(mixed $value): string
    {
        $phone = preg_replace('/[^0-9+]/', '', (string) $value);
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0'.$phone;
        }

        return $phone;
    }

    /**
     * Bulk upsert waybills — same logic as JntWaybillFastImport::bulkUpsert.
     * On conflict, only update status-related fields. Static fields are written once on INSERT.
     *
     * @param  array  $allColumns  Full column list for the INSERT
     * @param  array  $statusFields  Columns to update on conflict
     * @return array{inserted: int, updated: int, skipped: int}
     */
    protected function bulkUpsert(array $data, array $allColumns, array $statusFields, Upload $upload): array
    {
        $colList = implode(', ', $allColumns);
        $rowPlaceholder = '('.implode(', ', array_fill(0, count($allColumns), '?')).')';
        $valuesList = implode(', ', array_fill(0, count($data), $rowPlaceholder));

        $updateSet = implode(', ', array_map(
            fn ($col) => "{$col} = EXCLUDED.{$col}",
            $statusFields
        ));

        $bindings = [];
        foreach ($data as $row) {
            foreach ($allColumns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        $waybillNumbers = array_column($data, 'waybill_number');
        $existing = DB::table('waybills')
            ->whereIn('waybill_number', $waybillNumbers)
            ->pluck('waybill_number')
            ->flip()
            ->all();

        $existingStatuses = DB::table('waybills')
            ->whereIn('waybill_number', $waybillNumbers)
            ->pluck('status', 'waybill_number')
            ->all();

        $rows = DB::select("
            INSERT INTO waybills ({$colList})
            VALUES {$valuesList}
            ON CONFLICT (waybill_number) DO UPDATE SET
                {$updateSet}
            WHERE (
                waybills.status,
                waybills.signed_at,
                waybills.delivered_at,
                waybills.returned_at,
                waybills.rts_reason,
                waybills.remarks,
                waybills.shipping_cost,
                waybills.cod_amount,
                waybills.settlement_weight
            ) IS DISTINCT FROM (
                EXCLUDED.status,
                EXCLUDED.signed_at,
                EXCLUDED.delivered_at,
                EXCLUDED.returned_at,
                EXCLUDED.rts_reason,
                EXCLUDED.remarks,
                EXCLUDED.shipping_cost,
                EXCLUDED.cod_amount,
                EXCLUDED.settlement_weight
            )
            RETURNING waybill_number, xmax
        ", $bindings);

        $returnedNumbers = array_map(fn ($r) => $r->waybill_number, $rows);
        $batchInserted = count(array_filter($returnedNumbers, fn ($n) => ! isset($existing[$n])));
        $batchUpdated = count(array_filter($returnedNumbers, fn ($n) => isset($existing[$n])));
        $batchSkipped = count($data) - count($returnedNumbers);

        // Create tracking history entries for status changes
        $nowTs = now()->toDateTimeString();
        $historyEntries = [];
        $dataByNumber = array_column($data, null, 'waybill_number');
        foreach ($returnedNumbers as $wbNumber) {
            $newStatus = $dataByNumber[$wbNumber]['status'] ?? null;
            $oldStatus = $existingStatuses[$wbNumber] ?? null;

            if ($newStatus && $newStatus !== $oldStatus) {
                $historyEntries[] = [
                    'waybill_number' => $wbNumber,
                    'status' => $newStatus,
                    'previous_status' => $oldStatus,
                    'reason' => 'Google Sheet sync: '.$upload->original_filename,
                    'tracked_at' => $nowTs,
                    'created_at' => $nowTs,
                    'updated_at' => $nowTs,
                ];
            }
        }

        if (! empty($historyEntries)) {
            $waybillIds = DB::table('waybills')
                ->whereIn('waybill_number', array_column($historyEntries, 'waybill_number'))
                ->pluck('id', 'waybill_number')
                ->all();

            $insertData = [];
            foreach ($historyEntries as $entry) {
                $wbId = $waybillIds[$entry['waybill_number']] ?? null;
                if ($wbId) {
                    $insertData[] = [
                        'waybill_id' => $wbId,
                        'status' => $entry['status'],
                        'previous_status' => $entry['previous_status'],
                        'reason' => $entry['reason'],
                        'tracked_at' => $entry['tracked_at'],
                        'created_at' => $entry['created_at'],
                        'updated_at' => $entry['updated_at'],
                    ];
                }
            }

            if (! empty($insertData)) {
                WaybillTrackingHistory::insert($insertData);
            }
        }

        return ['inserted' => $batchInserted, 'updated' => $batchUpdated, 'skipped' => $batchSkipped];
    }

    protected function sanitizeDbError(string $message): string
    {
        // Strip potential connection details from DB errors
        return mb_substr($message, 0, 500);
    }
}
