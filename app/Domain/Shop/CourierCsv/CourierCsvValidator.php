<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\CourierExportRow;
use Illuminate\Support\Collection;

/**
 * Validates orders and export rows against a courier CSV schema.
 */
final class CourierCsvValidator
{
    public function __construct(
        private readonly CourierCsvSchemaRegistry $schemas,
    ) {}

    /**
     * Validate a collection of orders against the schema's required columns.
     *
     * @param Collection<int, Order> $orders
     * @return array{
     *     valid: bool,
     *     total: int,
     *     valid_count: int,
     *     invalid_count: int,
     *     schema: string,
     *     courier_code: string,
     *     required_columns: array<int, string>,
     *     column_count: int,
     *     orders: array<int, array{
     *         order_id: int,
     *         order_number: string,
     *         receiver_name: string,
     *         valid: bool,
     *         missing_columns: array<int, string>,
     *         missing_fields: array<int, array{column: string, field: string, value: mixed}>
     *     }>
     * }
     */
    public function validateOrders(Collection $orders, string $courierCode): array
    {
        $schema = $this->schemas->resolve($courierCode);
        $requiredColumns = $schema->requiredFields();
        $results = [];
        $validCount = 0;

        foreach ($orders as $order) {
            $missingFields = [];
            $missingColumns = [];

            foreach ($requiredColumns as $field => $label) {
                $value = $this->resolveOrderFieldValue($order, $field);

                if ($this->isInvalidValue($value, $field)) {
                    $missingColumns[] = $label;
                    $missingFields[] = [
                        'column' => $label,
                        'field' => $field,
                        'value' => $value,
                    ];
                }
            }

            $isValid = $missingColumns === [];
            if ($isValid) {
                $validCount++;
            }

            $results[] = [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'receiver_name' => $order->receiver_name,
                'valid' => $isValid,
                'missing_columns' => $missingColumns,
                'missing_fields' => $missingFields,
            ];
        }

        return [
            'valid' => $validCount === $orders->count(),
            'total' => $orders->count(),
            'valid_count' => $validCount,
            'invalid_count' => $orders->count() - $validCount,
            'schema' => $schema->name,
            'courier_code' => $schema->courierCode,
            'required_columns' => array_values($requiredColumns),
            'column_count' => $schema->columnCount(),
            'orders' => $results,
        ];
    }

    /**
     * Validate a collection of export rows against the schema's required columns.
     *
     * @param Collection<int, CourierExportRow> $rows
     * @return array{
     *     valid: bool,
     *     total: int,
     *     valid_count: int,
     *     invalid_count: int,
     *     rows: array<int, array{
     *         row_id: int,
     *         row_number: int,
     *         order_id: int,
     *         receiver_name: string,
     *         valid: bool,
     *         missing_columns: array<int, string>
     *     }>
     * }
     */
    public function validateRows(Collection $rows, string $courierCode): array
    {
        $schema = $this->schemas->resolve($courierCode);
        $requiredColumns = $schema->requiredFields();
        $results = [];
        $validCount = 0;

        foreach ($rows as $row) {
            $missingColumns = [];

            foreach ($requiredColumns as $field => $label) {
                $value = $this->resolveRowFieldValue($row, $field);

                if ($this->isInvalidValue($value, $field)) {
                    $missingColumns[] = $label;
                }
            }

            $isValid = $missingColumns === [];
            if ($isValid) {
                $validCount++;
            }

            $results[] = [
                'row_id' => $row->id,
                'row_number' => $row->row_number,
                'order_id' => $row->order_id,
                'receiver_name' => $row->receiver_name,
                'valid' => $isValid,
                'missing_columns' => $missingColumns,
            ];
        }

        return [
            'valid' => $validCount === $rows->count(),
            'total' => $rows->count(),
            'valid_count' => $validCount,
            'invalid_count' => $rows->count() - $validCount,
            'rows' => $results,
        ];
    }

    /**
     * Validate that the schema itself has all required columns defined.
     *
     * @return array{valid: bool, issues: array<int, string>}
     */
    public function validateSchemaIntegrity(string $courierCode): array
    {
        $schema = $this->schemas->resolve($courierCode);
        $issues = [];

        if ($schema->columnCount() === 0) {
            $issues[] = "Schema for {$schema->courierCode} has no columns defined.";
        }

        $requiredCount = count($schema->requiredFields());
        if ($requiredCount === 0) {
            $issues[] = "Schema for {$schema->courierCode} has no required columns.";
        }

        $fields = $schema->fields();
        $duplicates = array_diff_assoc($fields, array_unique($fields));
        if (! empty($duplicates)) {
            $issues[] = "Duplicate field mappings: " . implode(', ', array_unique($duplicates));
        }

        return [
            'valid' => $issues === [],
            'issues' => $issues,
        ];
    }

    /**
     * Get a summary of errors for throwing ValidationException.
     *
     * @param Collection<int, Order> $orders
     * @return array<int, string>
     */
    public function getExportBlockingErrors(Collection $orders, string $courierCode): array
    {
        $schema = $this->schemas->resolve($courierCode);
        $requiredColumns = $schema->requiredFields();
        $errors = [];

        foreach ($orders as $order) {
            $missing = [];

            foreach ($requiredColumns as $field => $label) {
                $value = $this->resolveOrderFieldValue($order, $field);

                if ($this->isInvalidValue($value, $field)) {
                    $missing[] = $label;
                }
            }

            if ($missing !== []) {
                $errors[] = "{$order->order_number}: " . implode(', ', $missing);
            }
        }

        return $errors;
    }

    private function isInvalidValue(mixed $value, string $field): bool
    {
        if (blank($value)) {
            return true;
        }

        if (is_numeric($value) && (float) $value <= 0 && $field !== 'cod_amount') {
            return true;
        }

        return false;
    }

    private function resolveOrderFieldValue(Order $order, string $field): mixed
    {
        return match ($field) {
            'order_number' => $order->order_number,
            'receiver_name' => $order->receiver_name,
            'phone_number' => $order->receiver_phone,
            'complete_address' => $order->receiver_address,
            'province' => $order->state,
            'city' => $order->city,
            'barangay' => $order->barangay,
            'product_name' => $order->relationLoaded('shopItems') && $order->shopItems->isNotEmpty()
                ? $order->shopItems->map(fn ($item) => "{$item->product_name} x{$item->quantity}")->implode(', ')
                : $order->product?->name,
            'quantity' => $order->relationLoaded('shopItems') && $order->shopItems->isNotEmpty()
                ? (int) $order->shopItems->sum('quantity')
                : (int) $order->quantity,
            'cod_amount' => $order->cod_amount,
            'remarks' => $order->notes,
            'sender_name' => (string) config('services.shop.sender_name'),
            'sender_phone' => (string) config('services.shop.sender_phone'),
            'sender_address' => (string) config('services.shop.sender_address'),
            'sender_province' => (string) config('services.shop.sender_province'),
            'sender_city' => (string) config('services.shop.sender_city'),
            default => null,
        };
    }

    private function resolveRowFieldValue(CourierExportRow $row, string $field): mixed
    {
        return match ($field) {
            'order_number' => $row->order?->order_number ?? $row->order_id,
            'receiver_name' => $row->receiver_name,
            'phone_number' => $row->phone_number,
            'complete_address' => $row->complete_address,
            'province' => $row->province,
            'city' => $row->city,
            'barangay' => $row->barangay,
            'product_name' => $row->product_name,
            'quantity' => $row->quantity,
            'cod_amount' => $row->cod_amount,
            'remarks' => $row->remarks,
            'sender_name' => (string) config('services.shop.sender_name'),
            'sender_phone' => (string) config('services.shop.sender_phone'),
            'sender_address' => (string) config('services.shop.sender_address'),
            'sender_province' => (string) config('services.shop.sender_province'),
            'sender_city' => (string) config('services.shop.sender_city'),
            default => null,
        };
    }
}
