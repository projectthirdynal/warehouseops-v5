<?php

namespace App\Imports;

use App\Models\Upload;
use App\Models\Waybill;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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

    public function __construct(Upload $upload, int $userId)
    {
        $this->upload = $upload;
        $this->userId = $userId;
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            try {
                $this->processRow($row->toArray(), $index + 2); // +2 for header row offset
            } catch (\Exception $e) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'error' => $e->getMessage(),
                ];
                $this->errorCount++;
                $this->upload->incrementError();
            }
        }
    }

    protected function processRow(array $row, int $rowNumber): void
    {
        $data = $this->mapRowToData($row);

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

        // Check if waybill exists
        $existingWaybill = Waybill::where('waybill_number', $data['waybill_number'])->first();

        if ($existingWaybill) {
            // Update existing waybill
            $existingWaybill->update($data);
            $this->updatedCount++;
        } else {
            // Create new waybill
            $data['uploaded_by'] = $this->userId;
            $data['upload_id'] = $this->upload->id;
            $data['courier_provider'] = 'J&T';
            Waybill::create($data);
        }

        $this->successCount++;
        $this->upload->incrementSuccess();
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
        return 500;
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
