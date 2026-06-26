<?php

namespace App\Imports;

use App\Models\Upload;
use App\Models\WaybillTrackingHistory;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * Flash Express CSV/Excel import using streaming reader.
 *
 * Column mapping based on Flash Express PH merchant export format:
 * PU time, Operated by, Sub-account Name, Source, Parcel Type, Order No.,
 * Tracking No., The original tracking No., Return tracking No., Status,
 * Sender, Sender phone, Sender address, Consignee, Consignee phone,
 * Consignee phone2, Consignee address, Item type, Remark1, Remark2, Remark3,
 * weight, size, Chargeable Weight, Product Type, Estimated Delivery Time,
 * Standard Shipping Fee, Packaging Fee, COD fee, COD Transfer Fee, label cost,
 * Flash Care, Declared value, KA Discount, Ordinary Discount, Total charge,
 * COD Amt, Delivery time, Signer, Signer type
 */
class FlashWaybillFastImport
{
    protected Upload $upload;
    protected int $userId;
    protected array $errors = [];
    protected int $successCount = 0;
    protected int $errorCount = 0;
    protected int $insertedCount = 0;
    protected int $updatedCount = 0;
    protected int $skippedCount = 0;
    // PostgreSQL has a 65535 bind parameter limit per query.
    // ALL_COLUMNS has 32 fields → 65535 / 32 ≈ 2048 max rows per upsert.
    // Use 1500 to leave headroom and still get good throughput.
    protected int $batchSize = 1500;
    protected int $batchCount = 0;
    protected ?\App\Domain\Courier\Services\StatusMapper $statusMapper = null;

    // Limit error collection to prevent memory exhaustion on large files with many errors
    protected const MAX_ERRORS_COLLECTED = 1000;
    protected const MAX_ERRORS_RETURNED = 100;

    protected const COLUMN_MAP = [
        'waybill_number'  => ['Tracking No.', 'tracking_no', 'Tracking No', 'PNO'],
        'status'          => ['Status', 'status'],
        'receiver_name'   => ['Consignee', 'consignee', 'Consignee Name'],
        'receiver_phone'  => ['Consignee phone', 'consignee_phone', 'Consignee Phone'],
        'receiver_address' => ['Consignee address', 'consignee_address', 'Consignee Address'],
        'sender_name'     => ['Sender', 'sender', 'Sender Name'],
        'sender_phone'    => ['Sender phone', 'sender_phone', 'Sender Phone'],
        'sender_address'  => ['Sender address', 'sender_address', 'Sender Address'],
        'item_name'       => ['Remark1', 'remark1', 'Item type'],
        'remarks'         => ['Remark2', 'remark2'],
        'rts_reason'      => ['Remark3', 'remark3'],
        'weight'          => ['weight', 'Weight'],
        'shipping_cost'   => ['Standard Shipping Fee', 'Total charge', 'total_charge'],
        'cod_amount'      => ['COD Amt', 'cod_amt', 'COD Amount'],
        'cod_fee'         => ['COD fee', 'cod_fee'],
        'item_value'      => ['Declared value', 'declared_value'],
        'submitted_at'    => ['PU time', 'pu_time', 'Pickup Time'],
        'delivered_at'    => ['Delivery time', 'delivery_time'],
        'signer'          => ['Signer', 'signer'],
        'order_no'        => ['Order No.', 'order_no', 'Order No'],
        'express_type'    => ['Product Type', 'product_type'],
        'settlement_weight' => ['Chargeable Weight', 'chargeable_weight'],
    ];

    protected const ALL_COLUMNS = [
        'waybill_number', 'creator_code', 'status',
        'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
        'settlement_weight', 'shipping_cost', 'cod_amount', 'amount',
        'submitted_at', 'rts_reason', 'remarks', 'express_type',
        'sender_name', 'sender_phone', 'sender_province', 'sender_city',
        'item_name', 'item_qty', 'item_value',
        'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
        'created_at', 'updated_at',
    ];

    protected const STATUS_FIELDS = [
        'status', 'delivered_at', 'returned_at', 'rts_reason', 'remarks',
        'shipping_cost', 'cod_amount', 'settlement_weight', 'amount', 'upload_id', 'uploaded_by', 'updated_at',
    ];

    public function __construct(Upload $upload, int $userId)
    {
        $this->upload = $upload;
        $this->userId = $userId;
    }

    public function import(string $filePath): void
    {
        $batch = [];
        $rowNumber = 0;
        $now = now()->toDateTimeString();

        // Resolve StatusMapper once (was: app() per row → 100k+ container resolutions)
        $this->statusMapper = app(\App\Domain\Courier\Services\StatusMapper::class);

        // Defer WAL fsync per commit during bulk import
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
                $counts = $this->bulkUpsert($batch);
                $batch = [];

                DB::table('uploads')->where('id', $this->upload->id)->update([
                    'success_rows'   => DB::raw("success_rows + {$flushed}"),
                    'processed_rows' => DB::raw("processed_rows + {$flushed}"),
                    'inserted_rows'  => DB::raw("inserted_rows + {$counts['inserted']}"),
                    'updated_rows'   => DB::raw("updated_rows + {$counts['updated']}"),
                    'skipped_rows'   => DB::raw("skipped_rows + {$counts['skipped']}"),
                    'total_rows'     => $rowNumber,
                ]);
            }
        });

        if (!empty($batch)) {
            $flushed = count($batch);
            $counts = $this->bulkUpsert($batch);
            DB::table('uploads')->where('id', $this->upload->id)->update([
                'success_rows'   => DB::raw("success_rows + {$flushed}"),
                'processed_rows' => DB::raw("processed_rows + {$flushed}"),
                'inserted_rows'  => DB::raw("inserted_rows + {$counts['inserted']}"),
                'updated_rows'   => DB::raw("updated_rows + {$counts['updated']}"),
                'skipped_rows'   => DB::raw("skipped_rows + {$counts['skipped']}"),
                'total_rows'     => $this->successCount + $this->errorCount,
            ]);
        }

        DB::table('uploads')->where('id', $this->upload->id)->update([
            'error_rows'     => $this->errorCount,
            'processed_rows' => $this->successCount + $this->errorCount,
            'total_rows'     => $this->successCount + $this->errorCount,
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

        // Validate: must have tracking number
        if (empty($data['waybill_number'])) {
            throw new \InvalidArgumentException('Missing tracking number.');
        }

        // Clean tracking number (Flash CSVs sometimes have leading tabs)
        $data['waybill_number'] = trim($data['waybill_number'], " \t\n\r\0\x0B");

        // Ensure required NOT NULL fields have defaults
        $data['receiver_name'] = $data['receiver_name'] ?? 'Unknown';
        $data['receiver_phone'] = $data['receiver_phone'] ?? '';
        $data['receiver_address'] = $data['receiver_address'] ?? '';

        // Use cached StatusMapper instance
        $data['status'] = isset($data['status'])
            ? $this->statusMapper->resolve('FLASH', trim($data['status']))->value
            : 'PENDING';

        // Parse address into components (Flash gives one combined address field)
        if (!empty($data['receiver_address'])) {
            $parsed = $this->parseFlashAddress($data['receiver_address']);
            $data['state'] = $parsed['state'];
            $data['city'] = $parsed['city'];
            $data['barangay'] = $parsed['barangay'];
            // Keep full address as-is
        }

        // Parse sender address for province/city
        if (!empty($data['sender_address'])) {
            $senderParsed = $this->parseFlashAddress($data['sender_address']);
            $data['sender_province'] = $senderParsed['state'];
            $data['sender_city'] = $senderParsed['city'];
            unset($data['sender_address']);
        }

        // Set delivered_at / returned_at from actual courier data — NOT upload time
        // Flash CSV: "Delivery time" has date only when status=Delivered, empty for returns
        if ($data['status'] === 'DELIVERED' && !empty($data['delivered_at'])) {
            // delivered_at already set from "Delivery time" column — correct
        } elseif ($data['status'] === 'DELIVERED') {
            // No delivery time but status is delivered — leave null, don't fake it
            $data['delivered_at'] = null;
        }

        if ($data['status'] === 'RETURNED') {
            // Flash doesn't provide a return date — leave null rather than using upload time
            $data['returned_at'] = null;
            $data['delivered_at'] = null;
        }

        // Use order_no as creator_code
        if (!empty($data['order_no'])) {
            $data['creator_code'] = trim($data['order_no'], " \t");
            unset($data['order_no']);
        }

        // Clean up non-db fields
        unset($data['signer'], $data['cod_fee'], $data['weight']);

        // Defaults for fields Flash CSV doesn't have
        $data['item_qty'] = 1;
        $data['amount'] = $data['cod_amount'] ?? 0;

        // Common fields
        $data['courier_provider'] = 'FLASH';
        $data['upload_id'] = $this->upload->id;
        $data['uploaded_by'] = $this->userId;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        // Normalize to all columns
        $normalized = [];
        foreach (self::ALL_COLUMNS as $col) {
            $normalized[$col] = $data[$col] ?? null;
        }

        return $normalized;
    }

    /**
     * Parse Flash Express combined address into state/city/barangay.
     * Format: "description - Province - City - Barangay Barangay City Province"
     * or: "description Province City"
     */
    protected function parseFlashAddress(string $address): array
    {
        $result = ['state' => null, 'city' => null, 'barangay' => null];

        // Flash address format often has dashes separating province-city-barangay
        // e.g. "... Surigao-del-sur Tagbina Sayon Sayon Tagbina Surigao del Sur"
        // or: "... - Sultan-kudarat - Tacurong-city - Buenaflor ..."

        // Try dash-separated format first
        $parts = preg_split('/\s*-\s*/', $address);
        if (count($parts) >= 4) {
            // Usually: address - Province - City - Barangay [trailing]
            $result['state'] = trim($parts[count($parts) - 3] ?? '');
            $result['city'] = trim($parts[count($parts) - 2] ?? '');
            $result['barangay'] = trim(explode(' ', $parts[count($parts) - 1])[0] ?? '');

            // Clean up dashes in names
            $result['state'] = str_replace('-', ' ', $result['state']);
            $result['city'] = str_replace('-', ' ', $result['city']);

            return $result;
        }

        // Try comma-separated format: "address, barangay, city, province"
        $commaParts = array_map('trim', explode(',', $address));
        if (count($commaParts) >= 4) {
            $result['barangay'] = $commaParts[count($commaParts) - 3];
            $result['city'] = $commaParts[count($commaParts) - 2];
            $result['state'] = $commaParts[count($commaParts) - 1];
            return $result;
        }

        // Unable to parse — store full address in receiver_address, leave components null
        return $result;
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
        // Clean leading tabs (Flash CSV quirk)
        if (is_string($value)) {
            $value = trim($value, " \t");
        }

        if (in_array($field, ['submitted_at', 'delivered_at'])) {
            return $this->parseDateTime($value);
        }

        if (in_array($field, ['shipping_cost', 'cod_amount', 'cod_fee', 'item_value', 'settlement_weight'])) {
            return $this->parseNumeric($value);
        }

        if (in_array($field, ['receiver_phone', 'sender_phone'])) {
            return $this->cleanPhone($value);
        }

        return trim((string) $value);
    }

    protected function parseDateTime(mixed $value): ?string
    {
        if (empty($value)) return null;

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value, " \t");
        if ($value === '') return null;

        // Native strtotime is ~50x faster than Carbon::parse
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    protected function parseNumeric(mixed $value): float
    {
        if (empty($value)) return 0;
        $cleaned = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        return (float) ($cleaned ?: 0);
    }

    protected function cleanPhone(mixed $value): string
    {
        $phone = preg_replace('/[^0-9+]/', '', trim((string) $value));
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0' . $phone;
        }
        return $phone;
    }

    protected function bulkUpsert(array $data): array
    {
        $columns = self::ALL_COLUMNS;
        $colList = implode(', ', $columns);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valuesList = implode(', ', array_fill(0, count($data), $rowPlaceholder));

        $updateSet = implode(', ', array_map(
            fn($col) => "{$col} = EXCLUDED.{$col}",
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

        $rows = DB::select("
            INSERT INTO waybills ({$colList})
            VALUES {$valuesList}
            ON CONFLICT (waybill_number) DO UPDATE SET
                {$updateSet}
            WHERE (
                waybills.status,
                waybills.delivered_at,
                waybills.returned_at,
                waybills.rts_reason,
                waybills.remarks,
                waybills.shipping_cost,
                waybills.cod_amount,
                waybills.settlement_weight,
                waybills.amount
            ) IS DISTINCT FROM (
                EXCLUDED.status,
                EXCLUDED.delivered_at,
                EXCLUDED.returned_at,
                EXCLUDED.rts_reason,
                EXCLUDED.remarks,
                EXCLUDED.shipping_cost,
                EXCLUDED.cod_amount,
                EXCLUDED.settlement_weight,
                EXCLUDED.amount
            )
            RETURNING waybill_number, xmax
        ", $bindings);

        $returnedNumbers = array_map(fn($r) => $r->waybill_number, $rows);
        $batchInserted = count(array_filter($returnedNumbers, fn($n) => !isset($existing[$n])));
        $batchUpdated  = count(array_filter($returnedNumbers, fn($n) => isset($existing[$n])));
        $batchSkipped  = count($data) - count($returnedNumbers);

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
                    'reason' => 'Bulk import: ' . $this->upload->original_filename,
                    'tracked_at' => $nowTs,
                    'created_at' => $nowTs,
                    'updated_at' => $nowTs,
                ];
            }
        }

        if (!empty($historyEntries)) {
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

            if (!empty($insertData)) {
                WaybillTrackingHistory::insert($insertData);
            }
        }

        $this->insertedCount += $batchInserted;
        $this->updatedCount  += $batchUpdated;
        $this->skippedCount  += $batchSkipped;

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
}
