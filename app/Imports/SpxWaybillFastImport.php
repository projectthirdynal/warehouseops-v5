<?php

namespace App\Imports;

use Modules\Couriers\Services\StatusMapper;
use App\Models\Upload;
use App\Models\WaybillTrackingHistory;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * SPX Express (Shopee Express) import from a CSV/Excel file path.
 *
 * Used by Google Sheet sync — the public sheet is downloaded as a CSV and
 * streamed through FastExcel. Column mapping covers SPX export headers plus
 * common variants found in merchant Google Sheets.
 */
class SpxWaybillFastImport
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

    protected int $batchCount = 0;

    protected ?StatusMapper $statusMapper = null;

    protected const MAX_ERRORS_COLLECTED = 1000;

    protected const MAX_ERRORS_RETURNED = 100;

    /**
     * SPX Express column mapping.
     * SPX sheets typically use: Tracking Number, Order Status, Receiver Name,
     * Receiver Phone, Province, City, Barangay, Address, etc.
     */
    protected const COLUMN_MAP = [
        'waybill_number' => ['Tracking Number', 'tracking_number', 'Waybill Number', 'Tracking No', 'Tracking No.'],
        'creator_code' => ['Creator Code', 'creator_code', 'Order No', 'Order No.', 'Order Number'],
        'status' => ['Order Status', 'order_status', 'Status', 'Parcel Status'],
        'signed_at' => ['Signing Time', 'SigningTime', 'Signing Date', 'Signed At', 'Delivery Time'],
        'receiver_name' => ['Receiver', 'Receiver Name', 'Consignee', 'Consignee Name', 'Customer Name'],
        'receiver_phone' => ['Receiver Cellphone', 'Receiver Phone', 'Receiver Cell Phone', 'Consignee Phone', 'Phone', 'Phone Number', 'Contact Number'],
        'state' => ['Province', 'province', 'State'],
        'city' => ['City', 'city', 'Municipality'],
        'barangay' => ['Barangay', 'barangay', 'Brgy'],
        'receiver_address' => ['Address', 'Complete Address', 'Receiver Address', 'Consignee Address'],
        'shipping_cost' => ['Total Shipping Cost', 'Shipping Cost', 'Shipping Fee'],
        'cod_amount' => ['COD', 'Cod', 'COD Amount', 'Cod Amount'],
        'submitted_at' => ['Submission Time', 'SubmissionTime', 'Submitted At', 'Pickup Time'],
        'rts_reason' => ['RTS Reason', 'rts_reason', 'Return Reason'],
        'remarks' => ['Remarks', 'Remark', 'Notes'],
        'sender_name' => ['Sender Name', 'SenderName', 'Sender'],
        'item_name' => ['Item Name', 'Item', 'Product Name', 'Parcel Content'],
        'item_qty' => ['Number of Items', 'Quantity', 'Qty'],
        'item_value' => ['Item Value', 'Declared Value', 'Parcel Value'],
    ];

    protected const ALL_COLUMNS = [
        'waybill_number', 'creator_code', 'status', 'signed_at',
        'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
        'shipping_cost', 'cod_amount',
        'submitted_at', 'rts_reason', 'remarks',
        'sender_name',
        'item_name', 'item_qty', 'item_value',
        'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
        'created_at', 'updated_at',
    ];

    protected const STATUS_FIELDS = [
        'status', 'signed_at', 'delivered_at', 'returned_at', 'rts_reason', 'remarks',
        'shipping_cost', 'cod_amount', 'upload_id', 'uploaded_by', 'updated_at',
    ];

    public function __construct(Upload $upload, int $userId)
    {
        $this->upload = $upload;
        $this->userId = $userId;

        $columnCount = count(self::ALL_COLUMNS);
        $this->batchSize = (int) floor(65000 / $columnCount);
    }

    /**
     * Import from a CSV/Excel file path (for Google Sheet sync downloads).
     * Streams rows through FastExcel and upserts in batches.
     */
    public function import(string $filePath): void
    {
        $batch = [];
        $rowNumber = 0;
        $now = now()->toDateTimeString();

        $this->statusMapper = app(StatusMapper::class);

        $cancelled = false;
        $cancelException = new \RuntimeException('__import_cancelled__');

        try {
            (new FastExcel)->import($filePath, function ($row) use (&$batch, &$rowNumber, &$cancelled, $now, $cancelException) {
                $rowNumber++;

                if ($rowNumber % 100 === 0) {
                    if ($this->upload->fresh()->status === 'cancelled') {
                        $this->successCount = max(0, $this->successCount - count($batch));
                        $cancelled = true;
                        $batch = [];
                        throw $cancelException;
                    }
                }

                try {
                    $data = $this->mapRow($row, $now);
                    if ($data) {
                        $batch[] = $data;
                        $this->successCount++;
                    }
                } catch (\Throwable $e) {
                    if (count($this->errors) < self::MAX_ERRORS_COLLECTED) {
                        $this->errors[] = ['row' => $rowNumber, 'error' => $e->getMessage()];
                    }
                    $this->errorCount++;
                }

                if (count($batch) >= $this->batchSize) {
                    $this->batchCount++;

                    $flushed = count($batch);
                    $currentBatch = $batch;
                    $batch = [];
                    $successRowsToAdd = $flushed;
                    try {
                        $counts = $this->bulkUpsert($currentBatch);
                    } catch (\Throwable $e) {
                        if (count($this->errors) < self::MAX_ERRORS_COLLECTED) {
                            $this->errors[] = ['row' => $rowNumber, 'error' => $this->sanitizeDbError($e->getMessage())];
                        }
                        $this->errorCount += $flushed;
                        $this->successCount = max(0, $this->successCount - $flushed);
                        $successRowsToAdd = 0;
                        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
                    }

                    DB::table('uploads')->where('id', $this->upload->id)->update([
                        'success_rows' => DB::raw("success_rows + {$successRowsToAdd}"),
                        'processed_rows' => DB::raw("processed_rows + {$flushed}"),
                        'inserted_rows' => DB::raw("inserted_rows + {$counts['inserted']}"),
                        'updated_rows' => DB::raw("updated_rows + {$counts['updated']}"),
                        'skipped_rows' => DB::raw("skipped_rows + {$counts['skipped']}"),
                        'total_rows' => DB::raw("CASE WHEN total_rows > {$rowNumber} THEN total_rows ELSE {$rowNumber} END"),
                    ]);
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__import_cancelled__') {
                throw $e;
            }
        }

        if (! empty($batch)) {
            $flushed = count($batch);
            $successRowsToAdd = $flushed;
            try {
                $counts = $this->bulkUpsert($batch);
            } catch (\Throwable $e) {
                if (count($this->errors) < self::MAX_ERRORS_COLLECTED) {
                    $this->errors[] = ['row' => 'final_batch', 'error' => $this->sanitizeDbError($e->getMessage())];
                }
                $this->errorCount += $flushed;
                $this->successCount = max(0, $this->successCount - $flushed);
                $successRowsToAdd = 0;
                $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            }
            $totalRows = $this->successCount + $this->errorCount;
            DB::table('uploads')->where('id', $this->upload->id)->update([
                'success_rows' => DB::raw("success_rows + {$successRowsToAdd}"),
                'processed_rows' => DB::raw("processed_rows + {$flushed}"),
                'inserted_rows' => DB::raw("inserted_rows + {$counts['inserted']}"),
                'updated_rows' => DB::raw("updated_rows + {$counts['updated']}"),
                'skipped_rows' => DB::raw("skipped_rows + {$counts['skipped']}"),
                'total_rows' => DB::raw("CASE WHEN total_rows > {$totalRows} THEN total_rows ELSE {$totalRows} END"),
            ]);
        }

        $finalTotal = max($this->successCount + $this->errorCount, $rowNumber);
        DB::table('uploads')->where('id', $this->upload->id)->update([
            'error_rows' => $this->errorCount,
            'processed_rows' => DB::raw("CASE WHEN processed_rows > {$finalTotal} THEN processed_rows ELSE {$finalTotal} END"),
            'total_rows' => DB::raw("CASE WHEN total_rows > {$finalTotal} THEN total_rows ELSE {$finalTotal} END"),
        ]);
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

        if (empty($data['waybill_number'])) {
            throw new \InvalidArgumentException('Missing tracking number.');
        }

        $data['waybill_number'] = trim((string) $data['waybill_number'], " \t\n\r\0\x0B");

        $data['receiver_name'] = $data['receiver_name'] ?? 'Unknown';
        $data['receiver_phone'] = $data['receiver_phone'] ?? '';
        $data['receiver_address'] = $data['receiver_address'] ?? '';

        // SPX statuses are similar to J&T — use JNT mapping as fallback
        $data['status'] = isset($data['status'])
            ? $this->statusMapper->resolve('SPX', trim($data['status']))->value
            : 'PENDING';

        if ($data['status'] === 'DELIVERED' && isset($data['signed_at'])) {
            $data['delivered_at'] = $data['signed_at'];
        }
        if ($data['status'] === 'RETURNED' && isset($data['signed_at'])) {
            $data['returned_at'] = $data['signed_at'];
        }

        $data['item_qty'] = $data['item_qty'] ?? 1;
        $data['courier_provider'] = 'SPX';
        $data['upload_id'] = $this->upload->id;
        $data['uploaded_by'] = $this->userId;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $normalized = [];
        foreach (self::ALL_COLUMNS as $col) {
            $normalized[$col] = $data[$col] ?? null;
        }

        return $normalized;
    }

    protected function findValue(array $row, array $headers): mixed
    {
        $rowKeys = array_keys($row);
        foreach ($headers as $header) {
            $normalizedHeader = strtolower(trim((string) $header));
            foreach ($rowKeys as $key) {
                if (strtolower(trim((string) $key)) === $normalizedHeader && isset($row[$key]) && $row[$key] !== '') {
                    return $row[$key];
                }
            }
        }

        return null;
    }

    protected function transformValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['signed_at', 'submitted_at', 'delivered_at'])) {
            return $this->parseDateTime($value);
        }

        if (in_array($field, ['shipping_cost', 'cod_amount', 'item_value'])) {
            return $this->parseNumeric($value);
        }

        if ($field === 'item_qty') {
            return (int) $this->parseNumeric($value) ?: 1;
        }

        if ($field === 'receiver_phone') {
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
                waybills.cod_amount
            ) IS DISTINCT FROM (
                EXCLUDED.status,
                EXCLUDED.signed_at,
                EXCLUDED.delivered_at,
                EXCLUDED.returned_at,
                EXCLUDED.rts_reason,
                EXCLUDED.remarks,
                EXCLUDED.shipping_cost,
                EXCLUDED.cod_amount
            )
            RETURNING waybill_number, xmax
        ", $bindings);

        $returnedNumbers = array_map(fn ($r) => $r->waybill_number, $rows);
        $batchInserted = count(array_filter($returnedNumbers, fn ($n) => ! isset($existing[$n])));
        $batchUpdated = count(array_filter($returnedNumbers, fn ($n) => isset($existing[$n])));
        $batchSkipped = count($data) - count($returnedNumbers);

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
                    'reason' => 'Google Sheet sync: '.$this->upload->original_filename,
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
        $truncated = preg_replace('/\s+SQL:.*$/s', '', $message);

        return mb_substr($truncated ?: $message, 0, 500);
    }
}
