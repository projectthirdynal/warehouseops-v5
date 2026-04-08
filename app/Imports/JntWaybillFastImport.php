<?php

namespace App\Imports;

use App\Models\Upload;
use App\Models\Waybill;
use Carbon\Carbon;
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
    protected int $batchSize = 1000;

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

    protected const UPSERT_FIELDS = [
        'creator_code', 'status', 'sign_for_pictures', 'signed_at',
        'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
        'payment_method', 'settlement_weight', 'shipping_cost', 'cod_amount',
        'submitted_at', 'rts_reason', 'remarks', 'express_type',
        'sender_name', 'sender_phone', 'sender_province', 'sender_city',
        'item_name', 'item_qty', 'item_value', 'valuation_fee',
        'delivered_at', 'returned_at', 'updated_at',
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

        // Stream read the file - very memory efficient
        (new FastExcel)->import($filePath, function ($row) use (&$batch, &$rowNumber, $now) {
            $rowNumber++;

            try {
                $data = $this->mapRow($row, $now);

                if ($data) {
                    $batch[] = $data;
                    $this->successCount++;
                }
            } catch (\Exception $e) {
                $this->errors[] = ['row' => $rowNumber, 'error' => $e->getMessage()];
                $this->errorCount++;
            }

            // Bulk insert when batch is full
            if (count($batch) >= $this->batchSize) {
                // Check if cancelled
                if ($this->upload->fresh()->status === 'cancelled') {
                    $batch = [];
                    return;
                }

                $this->bulkUpsert($batch);
                $this->upload->increment('success_rows', count($batch));
                $this->upload->increment('processed_rows', count($batch));
                $batch = [];
            }
        });

        // Insert remaining rows
        if (!empty($batch)) {
            $this->bulkUpsert($batch);
            $this->upload->increment('success_rows', count($batch));
            $this->upload->increment('processed_rows', count($batch));
        }

        // Update error count
        if ($this->errorCount > 0) {
            $this->upload->increment('error_rows', $this->errorCount);
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
            return null; // Skip rows without waybill number
        }

        // Map status
        $data['status'] = isset($data['status']) ? Waybill::mapCourierStatus('JNT', $data['status']) : 'PENDING';

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
        if (empty($value)) return null;

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseNumeric(mixed $value): float
    {
        if (empty($value)) return 0;
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }

    protected function cleanPhone(mixed $value): string
    {
        $phone = preg_replace('/[^0-9+]/', '', (string) $value);
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0' . $phone;
        }
        return $phone;
    }

    protected function bulkUpsert(array $data): void
    {
        Waybill::upsert($data, ['waybill_number'], self::UPSERT_FIELDS);
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
        return array_slice($this->errors, 0, 100);
    }
}
