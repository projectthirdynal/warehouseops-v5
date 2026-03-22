<?php

namespace App\Imports;

use App\Models\Upload;
use App\Models\Waybill;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class JntWaybillImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected Upload $upload;
    protected int $userId;
    protected array $errors = [];
    protected int $successCount = 0;
    protected int $errorCount = 0;
    protected int $updatedCount = 0;

    // Column mapping from J&T Excel headers to our database fields
    protected const COLUMN_MAP = [
        'waybill_number' => ['waybill_number', 'waybill number', 'waybill no', 'tracking number', 'tracking no'],
        'creator_code' => ['creator_code', 'creator code'],
        'status' => ['order_status', 'order status', 'status'],
        'sign_for_pictures' => ['sign_for_pictures', 'sign for pictures'],
        'signed_at' => ['signingtime', 'signing time', 'signing_time', 'delivered_at'],
        'receiver_name' => ['receiver', 'receiver_name', 'receiver name', 'consignee', 'consignee name'],
        'receiver_phone' => ['receiver_cellphone', 'receiver cellphone', 'receiver_phone', 'receiver phone', 'consignee phone'],
        'state' => ['province', 'receiver_province', 'receiver province'],
        'city' => ['city', 'receiver_city', 'receiver city'],
        'barangay' => ['barangay', 'brgy', 'receiver_barangay'],
        'receiver_address' => ['address', 'receiver_address', 'receiver address', 'full_address'],
        'payment_method' => ['payment_method', 'payment method'],
        'settlement_weight' => ['settlement_weight', 'settlement weight', 'weight'],
        'shipping_cost' => ['total_shipping_cost', 'total shipping cost', 'shipping_cost', 'shipping cost', 'freight'],
        'cod_amount' => ['cod', 'cod_amount', 'cod amount', 'collect_on_delivery'],
        'submitted_at' => ['submission_time', 'submission time', 'created_at', 'order_date', 'order date'],
        'rts_reason' => ['rts_reason', 'rts reason', 'return_reason', 'return reason'],
        'remarks' => ['remarks', 'notes', 'comment'],
        'express_type' => ['express_type', 'express type', 'service_type', 'service type'],
        'sender_name' => ['shipping_customer', 'shipping customer', 'sender_name', 'sender name', 'shipper'],
        'sender_phone' => ['sender_cellphone', 'sender cellphone', 'sender_phone', 'sender phone'],
        'sender_province' => ['sender_province', 'sender province'],
        'sender_city' => ['sender_city', 'sender city'],
        'item_name' => ['item_name', 'item name', 'product', 'product_name', 'product name'],
        'item_qty' => ['number_of_items', 'number of items', 'item_qty', 'quantity', 'qty'],
        'item_value' => ['item_value', 'item value', 'declared_value', 'declared value'],
        'valuation_fee' => ['valuation_fee', 'valuation fee', 'insurance_fee'],
    ];

    // Fields to update on conflict (upsert)
    protected const UPSERT_UPDATE_FIELDS = [
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

    public function collection(Collection $rows): void
    {
        $batchData = [];
        $now = now();

        foreach ($rows as $index => $row) {
            try {
                $data = $this->mapRowToData($row->toArray());

                // Validate required fields
                if (empty($data['waybill_number'])) {
                    throw new \Exception('Waybill number is required');
                }

                if (empty($data['receiver_name'])) {
                    throw new \Exception('Receiver name is required');
                }

                if (empty($data['receiver_phone'])) {
                    throw new \Exception('Receiver phone is required');
                }

                // Add common fields
                $data['uploaded_by'] = $this->userId;
                $data['upload_id'] = $this->upload->id;
                $data['courier_provider'] = 'J&T';
                $data['created_at'] = $now;
                $data['updated_at'] = $now;

                // Convert Carbon dates to strings for bulk insert
                foreach (['signed_at', 'submitted_at', 'delivered_at', 'returned_at'] as $dateField) {
                    if (isset($data[$dateField]) && $data[$dateField] instanceof Carbon) {
                        $data[$dateField] = $data[$dateField]->toDateTimeString();
                    }
                }

                $batchData[] = $data;
                $this->successCount++;

            } catch (\Exception $e) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'error' => $e->getMessage(),
                ];
                $this->errorCount++;
            }
        }

        // Bulk upsert - single query for entire chunk
        if (!empty($batchData)) {
            $this->bulkUpsert($batchData);

            // Update upload progress in single query
            $this->upload->increment('success_rows', count($batchData));
            $this->upload->increment('processed_rows', count($batchData) + count($this->errors));

            if ($this->errorCount > 0) {
                $this->upload->increment('error_rows', count($this->errors));
            }
        }
    }

    protected function bulkUpsert(array $data): void
    {
        // Ensure all rows have the same columns for PostgreSQL upsert
        $allColumns = [
            'waybill_number', 'creator_code', 'status', 'sign_for_pictures', 'signed_at',
            'receiver_name', 'receiver_phone', 'state', 'city', 'barangay', 'receiver_address',
            'payment_method', 'settlement_weight', 'shipping_cost', 'cod_amount',
            'submitted_at', 'rts_reason', 'remarks', 'express_type',
            'sender_name', 'sender_phone', 'sender_province', 'sender_city',
            'item_name', 'item_qty', 'item_value', 'valuation_fee',
            'delivered_at', 'returned_at', 'courier_provider', 'upload_id', 'uploaded_by',
            'created_at', 'updated_at',
        ];

        // Normalize all rows to have the same columns
        $normalizedData = array_map(function ($row) use ($allColumns) {
            $normalized = [];
            foreach ($allColumns as $col) {
                $normalized[$col] = $row[$col] ?? null;
            }
            return $normalized;
        }, $data);

        // Use upsert - insert or update on duplicate waybill_number
        Waybill::upsert(
            $normalizedData,
            ['waybill_number'], // Unique key
            self::UPSERT_UPDATE_FIELDS // Fields to update on conflict
        );
    }

    protected function mapRowToData(array $row): array
    {
        $data = [];

        foreach (self::COLUMN_MAP as $field => $possibleHeaders) {
            $value = $this->findValue($row, $possibleHeaders);

            if ($value !== null) {
                $data[$field] = $this->transformValue($field, $value);
            }
        }

        // Map status from J&T to internal status
        if (isset($data['status'])) {
            $data['status'] = Waybill::mapJntStatus($data['status']);
        } else {
            $data['status'] = 'PENDING';
        }

        // Set delivered_at based on signed_at for delivered status
        if ($data['status'] === 'DELIVERED' && isset($data['signed_at'])) {
            $data['delivered_at'] = $data['signed_at'];
        }

        // Set returned_at for returned status
        if ($data['status'] === 'RETURNED' && isset($data['signed_at'])) {
            $data['returned_at'] = $data['signed_at'];
        }

        return $data;
    }

    protected function findValue(array $row, array $possibleHeaders): mixed
    {
        foreach ($possibleHeaders as $header) {
            // Try exact match
            if (isset($row[$header]) && $row[$header] !== '' && $row[$header] !== null) {
                return $row[$header];
            }

            // Try snake_case version
            $snakeHeader = str_replace(' ', '_', strtolower($header));
            if (isset($row[$snakeHeader]) && $row[$snakeHeader] !== '' && $row[$snakeHeader] !== null) {
                return $row[$snakeHeader];
            }

            // Try with underscores removed
            $noUnderscoreHeader = str_replace('_', '', strtolower($header));
            if (isset($row[$noUnderscoreHeader]) && $row[$noUnderscoreHeader] !== '' && $row[$noUnderscoreHeader] !== null) {
                return $row[$noUnderscoreHeader];
            }
        }

        return null;
    }

    protected function transformValue(string $field, mixed $value): mixed
    {
        // Handle date/time fields
        if (in_array($field, ['signed_at', 'submitted_at', 'delivered_at', 'returned_at'])) {
            return $this->parseDateTime($value);
        }

        // Handle boolean fields
        if ($field === 'sign_for_pictures') {
            return strtolower(trim((string) $value)) === 'yes';
        }

        // Handle numeric fields
        if (in_array($field, ['shipping_cost', 'cod_amount', 'settlement_weight', 'item_value', 'valuation_fee'])) {
            return $this->parseNumeric($value);
        }

        if ($field === 'item_qty') {
            return (int) $this->parseNumeric($value) ?: 1;
        }

        // Clean phone numbers
        if (in_array($field, ['receiver_phone', 'sender_phone'])) {
            return $this->cleanPhoneNumber($value);
        }

        // Trim strings
        return trim((string) $value);
    }

    protected function parseDateTime(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        // Handle Excel numeric dates
        if (is_numeric($value)) {
            try {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
            } catch (\Exception $e) {
                return null;
            }
        }

        // Try common date formats
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'm/d/Y H:i:s',
            'm/d/Y H:i',
            'm/d/Y',
            'd-m-Y H:i:s',
            'd-m-Y',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Exception $e) {
                continue;
            }
        }

        // Last resort: let Carbon try to parse it
        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseNumeric(mixed $value): float
    {
        if (empty($value)) {
            return 0;
        }

        // Remove currency symbols and commas
        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return (float) $cleaned;
    }

    protected function cleanPhoneNumber(mixed $value): string
    {
        $phone = preg_replace('/[^0-9+]/', '', (string) $value);

        // Ensure PH numbers start with proper format
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0' . $phone;
        }

        return $phone;
    }

    public function chunkSize(): int
    {
        // PostgreSQL has 65535 parameter limit
        // With 34 columns, max safe batch = ~1900 rows
        // Using 1000 for safety margin
        return 1000;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
