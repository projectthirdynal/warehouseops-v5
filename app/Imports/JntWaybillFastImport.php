<?php

namespace App\Imports;

use App\Domain\Courier\Services\StatusMapper;
use App\Models\Upload;
use App\Models\WaybillTrackingHistory;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * Fast Excel Import using Spout (streaming reader)
 * 10-50x faster than PhpSpreadsheet for large files
 */
class JntWaybillFastImport
{
    protected Upload $upload;

    protected int $userId;

    protected array $errors = [];

    protected int $successCount = 0;

    protected int $errorCount = 0;

    protected int $insertedCount = 0;

    protected int $updatedCount = 0;

    protected int $skippedCount = 0;

    protected int $batchSize;

    protected int $batchCount = 0; // batches flushed so far

    protected ?StatusMapper $statusMapper = null;

    // Limit error collection to prevent memory exhaustion on large files with many errors
    protected const MAX_ERRORS_COLLECTED = 1000;

    protected const MAX_ERRORS_RETURNED = 100;

    protected const COLUMN_MAP = [
        'waybill_number' => ['waybill_number', 'waybill number', 'Waybill Number'],
        'creator_code' => ['creator_code', 'creator code', 'Creator Code'],
        'status' => ['order_status', 'order status', 'Order Status'],
        'sign_for_pictures' => ['sign_for_pictures', 'sign for pictures', 'Sign For Pictures'],
        'signed_at' => ['signingtime', 'SigningTime'],
        'receiver_name' => ['receiver', 'Receiver'],
        'receiver_phone' => ['receiver_cellphone', 'Receiver Cellphone'],
        'state' => ['province', 'Province'],
        'city' => ['city', 'City'],
        'barangay' => ['barangay', 'Barangay'],
        'receiver_address' => ['address', 'Address'],
        'payment_method' => ['payment_method', 'Payment Method'],
        'settlement_weight' => ['settlement_weight', 'Settlement Weight'],
        'shipping_cost' => ['total_shipping_cost', 'Total Shipping Cost'],
        'cod_amount' => ['cod', 'Cod'],
        'submitted_at' => ['submission_time', 'Submission Time'],
        'rts_reason' => ['rts_reason', 'RTS Reason'],
        'remarks' => ['remarks', 'Remarks'],
        'express_type' => ['express_type', 'Express Type'],
        'sender_name' => ['sender_name', 'Sender Name', 'shipping_customer', 'Shipping Customer'],
        'sender_phone' => ['sender_cellphone', 'Sender Cellphone'],
        'sender_province' => ['sender_province', 'Sender Province'],
        'sender_city' => ['sender_city', 'Sender City'],
        'item_name' => ['item_name', 'Item Name'],
        'item_qty' => ['number_of_items', 'Number Of Items'],
        'item_value' => ['item_value', 'Item Value'],
        'valuation_fee' => ['valuation_fee', 'Valuation Fee'],
    ];

    protected const ALL_COLUMNS = [
        'waybill_number', 'creator_code', 'status', 'sign_for_pictures', 'signed_at',
        'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
        'payment_method', 'settlement_weight', 'shipping_cost', 'cod_amount',
        'submitted_at', 'rts_reason', 'remarks', 'express_type',
        'sender_name', 'sender_phone', 'sender_province', 'sender_city',
        'item_name', 'item_qty', 'item_value', 'valuation_fee',
        'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
        'created_at', 'updated_at',
    ];

    // On conflict: only update status-related fields. Static fields (name, address, etc.)
    // are written once on INSERT and never overwritten by re-imports of the same file.
    protected const STATUS_FIELDS = [
        'status', 'signed_at', 'delivered_at', 'returned_at', 'rts_reason', 'remarks',
        'shipping_cost', 'cod_amount', 'settlement_weight', 'upload_id', 'uploaded_by', 'updated_at',
    ];

    public function __construct(Upload $upload, int $userId)
    {
        $this->upload = $upload;
        $this->userId = $userId;

        // PostgreSQL has a 65535 bind parameter limit per query.
        // Calculate safe batch size: 65000 / column_count, leaving 535 params as safety margin
        $columnCount = count(self::ALL_COLUMNS);
        $this->batchSize = (int) floor(65000 / $columnCount);
    }

    public function import(string $filePath): void
    {
        $batch = [];
        $rowNumber = 0;
        $now = now()->toDateTimeString();

        // Resolve StatusMapper once instead of per-row container lookups
        $this->statusMapper = app(StatusMapper::class);

        // Trade durability for speed during bulk import: WAL fsync deferred per commit.
        // SET (not SET LOCAL) applies session-wide; SET LOCAL would revert immediately in autocommit.
        DB::statement('SET synchronous_commit = OFF');

        try {
            (new FastExcel)->import($filePath, function ($row) use (&$batch, &$rowNumber, $now) {
                $rowNumber++;

                try {
                    $data = $this->mapRow($row, $now);
                    if ($data) {
                        $batch[] = $data;
                        $this->successCount++;
                    }
                } catch (\Throwable $e) {
                    // Limit error collection to prevent memory exhaustion
                    if (count($this->errors) < self::MAX_ERRORS_COLLECTED) {
                        $this->errors[] = ['row' => $rowNumber, 'error' => $e->getMessage()];
                    }
                    $this->errorCount++;
                }

                if (count($batch) >= $this->batchSize) {
                    $this->batchCount++;

                    // Check cancellation every 10 batches (one SELECT per 30k rows)
                    if ($this->batchCount % 10 === 0) {
                        if ($this->upload->fresh()->status === 'cancelled') {
                            $batch = [];

                            return;
                        }
                    }

                    $flushed = count($batch);
                    $currentBatch = $batch;
                    $batch = [];
                    try {
                        $counts = $this->bulkUpsert($currentBatch);
                    } catch (\Throwable $e) {
                        if (count($this->errors) < self::MAX_ERRORS_COLLECTED) {
                            $this->errors[] = ['row' => $rowNumber, 'error' => $this->sanitizeDbError($e->getMessage())];
                        }
                        $this->errorCount += $flushed;
                        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => $flushed];
                    }

                    // Single combined write — increments collapsed to 1 UPDATE
                    DB::table('uploads')->where('id', $this->upload->id)->update([
                        'success_rows' => DB::raw("success_rows + {$flushed}"),
                        'processed_rows' => DB::raw("processed_rows + {$flushed}"),
                        'inserted_rows' => DB::raw("inserted_rows + {$counts['inserted']}"),
                        'updated_rows' => DB::raw("updated_rows + {$counts['updated']}"),
                        'skipped_rows' => DB::raw("skipped_rows + {$counts['skipped']}"),
                        'total_rows' => DB::raw("CASE WHEN total_rows > {$rowNumber} THEN total_rows ELSE {$rowNumber} END"),
                    ]);
                }
            });

            if (! empty($batch)) {
                $flushed = count($batch);
                try {
                    $counts = $this->bulkUpsert($batch);
                } catch (\Throwable $e) {
                    if (count($this->errors) < self::MAX_ERRORS_COLLECTED) {
                        $this->errors[] = ['row' => 'final_batch', 'error' => $this->sanitizeDbError($e->getMessage())];
                    }
                    $this->errorCount += $flushed;
                    $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => $flushed];
                }
                $totalRows = $this->successCount + $this->errorCount;
                DB::table('uploads')->where('id', $this->upload->id)->update([
                    'success_rows' => DB::raw("success_rows + {$flushed}"),
                    'processed_rows' => DB::raw("processed_rows + {$flushed}"),
                    'inserted_rows' => DB::raw("inserted_rows + {$counts['inserted']}"),
                    'updated_rows' => DB::raw("updated_rows + {$counts['updated']}"),
                    'skipped_rows' => DB::raw("skipped_rows + {$counts['skipped']}"),
                    'total_rows' => DB::raw("CASE WHEN total_rows > {$totalRows} THEN total_rows ELSE {$totalRows} END"),
                ]);
            }

            // Final totals
            $finalTotal = $this->successCount + $this->errorCount;
            DB::table('uploads')->where('id', $this->upload->id)->update([
                'error_rows' => $this->errorCount,
                'processed_rows' => DB::raw("CASE WHEN processed_rows > {$finalTotal} THEN processed_rows ELSE {$finalTotal} END"),
                'total_rows' => DB::raw("CASE WHEN total_rows > {$finalTotal} THEN total_rows ELSE {$finalTotal} END"),
            ]);
        } finally {
            DB::statement('SET synchronous_commit = ON');
        }
    }

    protected function mapRow(array $row, string $now): ?array
    {
        $data = [];

        foreach (self::COLUMN_MAP as $field => $possibleHeaders) {
            $value = $this->findValue($row, $possibleHeaders);
            if ($value !== null && $value !== '') {
                $data[$field] = $this->transformValue($field, $value);
            }
        }

        // Validate required fields
        if (empty($data['waybill_number'])) {
            throw new \InvalidArgumentException('Missing waybill number.');
        }

        // Ensure required NOT NULL fields have defaults
        $data['receiver_name'] = $data['receiver_name'] ?? 'Unknown';
        $data['receiver_phone'] = $data['receiver_phone'] ?? '';
        $data['receiver_address'] = $data['receiver_address'] ?? '';

        // Use cached StatusMapper (resolved once in import())
        $data['status'] = isset($data['status'])
            ? $this->statusMapper->resolve('JNT', $data['status'])->value
            : 'PENDING';

        // Set delivered_at / returned_at based on status
        if ($data['status'] === 'DELIVERED' && isset($data['signed_at'])) {
            $data['delivered_at'] = $data['signed_at'];
        }
        if ($data['status'] === 'RETURNED' && isset($data['signed_at'])) {
            $data['returned_at'] = $data['signed_at'];
        }

        // Add common fields
        $data['courier_provider'] = 'J&T';
        $data['upload_id'] = $this->upload->id;
        $data['uploaded_by'] = $this->userId;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        // Normalize to ensure all columns exist
        $normalized = [];
        foreach (self::ALL_COLUMNS as $col) {
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
        if (in_array($field, ['signed_at', 'submitted_at'])) {
            return $this->parseDateTime($value);
        }

        if ($field === 'sign_for_pictures') {
            return strtolower(trim((string) $value)) === 'yes';
        }

        if (in_array($field, ['shipping_cost', 'cod_amount', 'settlement_weight', 'item_value', 'valuation_fee'])) {
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

        // Native strtotime is ~50x faster than Carbon::parse for known formats
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

    protected function bulkUpsert(array $data): array
    {
        $columns = self::ALL_COLUMNS;
        $colList = implode(', ', $columns);
        $rowPlaceholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $valuesList = implode(', ', array_fill(0, count($data), $rowPlaceholder));

        $updateSet = implode(', ', array_map(
            fn ($col) => "{$col} = EXCLUDED.{$col}",
            self::STATUS_FIELDS
        ));

        $bindings = [];
        foreach ($data as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        // Pre-query to identify existing waybill numbers so we can reliably count
        // inserts vs updates while still returning xmax for PostgreSQL upsert diagnostics.
        $waybillNumbers = array_column($data, 'waybill_number');
        $existing = DB::table('waybills')
            ->whereIn('waybill_number', $waybillNumbers)
            ->pluck('waybill_number')
            ->flip()
            ->all();

        // Fetch existing statuses to detect changes for tracking history
        $existingStatuses = DB::table('waybills')
            ->whereIn('waybill_number', $waybillNumbers)
            ->pluck('status', 'waybill_number')
            ->all();

        // Skip the update entirely when courier-sync fields are unchanged — avoids
        // writing ~20 columns worth of WAL for rows that haven't moved.
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
                    'reason' => 'Bulk import: '.$this->upload->original_filename,
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

        $this->insertedCount += $batchInserted;
        $this->updatedCount += $batchUpdated;
        $this->skippedCount += $batchSkipped;

        return ['inserted' => $batchInserted, 'updated' => $batchUpdated, 'skipped' => $batchSkipped];
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getErrors(): array
    {
        return array_slice($this->errors, 0, self::MAX_ERRORS_RETURNED);
    }

    public function getInsertedCount(): int
    {
        return $this->insertedCount;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    private function sanitizeDbError(string $message): string
    {
        // Strip SQL statement from DB exception messages to prevent raw SQL leaking into error logs
        $truncated = preg_replace('/\s+SQL:.*$/s', '', $message);

        return mb_substr($truncated ?: $message, 0, 500);
    }
}
