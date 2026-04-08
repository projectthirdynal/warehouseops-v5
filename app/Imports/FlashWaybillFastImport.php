<?php

namespace App\Imports;

use App\Models\Upload;
use App\Models\Waybill;
use Carbon\Carbon;
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
    protected int $batchSize = 1000;

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

    protected const UPSERT_FIELDS = [
        'status', 'receiver_name', 'receiver_phone', 'state', 'city', 'barangay',
        'receiver_address', 'settlement_weight', 'shipping_cost', 'cod_amount',
        'submitted_at', 'rts_reason', 'remarks', 'express_type',
        'sender_name', 'sender_phone', 'sender_province', 'sender_city',
        'item_name', 'item_value',
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

            if (count($batch) >= $this->batchSize) {
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

        if (!empty($batch)) {
            $this->bulkUpsert($batch);
            $this->upload->increment('success_rows', count($batch));
            $this->upload->increment('processed_rows', count($batch));
        }

        if ($this->errorCount > 0) {
            $this->upload->increment('error_rows', $this->errorCount);
        }

        // Set total rows (avoids separate counting pass)
        $this->upload->update(['total_rows' => $this->successCount + $this->errorCount]);
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
            return null;
        }

        // Clean tracking number (Flash CSVs sometimes have leading tabs)
        $data['waybill_number'] = trim($data['waybill_number'], " \t\n\r\0\x0B");

        // Map status using StatusMapper
        $data['status'] = isset($data['status'])
            ? Waybill::mapCourierStatus('FLASH', trim($data['status']))
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

        // Set delivered_at / returned_at based on status
        if ($data['status'] === 'DELIVERED' && !empty($data['delivered_at'])) {
            // already set
        } elseif ($data['status'] === 'DELIVERED' && !empty($data['submitted_at'])) {
            $data['delivered_at'] = $data['submitted_at'];
        }

        if ($data['status'] === 'RETURNED') {
            $data['returned_at'] = $data['delivered_at'] ?? $now;
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
        // or "... - Sultan-kudarat - Tacurong-city - Buenaflor ..."

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

        $value = trim((string) $value, " \t");
        if ($value === '') return null;

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
