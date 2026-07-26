<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

use App\Domain\Order\Models\Order;
use Illuminate\Support\Collection;

/**
 * Runs validation in "test mode" — exercises the full validation pipeline
 * against orders or raw CSV data without creating an export batch.
 */
final class CourierCsvTestMode
{
    public function __construct(
        private readonly CourierCsvValidator $validator,
        private readonly CourierCsvSchemaRegistry $schemas,
        private readonly CourierCsvEncodingChecker $encodingChecker,
        private readonly CourierCsvTemplateBuilder $templates,
    ) {}

    /**
     * Run full validation against a set of orders for a courier.
     *
     * @param Collection<int, Order> $orders
     * @return array<string, mixed>
     */
    public function testOrders(Collection $orders, string $courierCode): array
    {
        $schema = $this->schemas->resolve($courierCode);
        $customSchema = $this->templates->resolveSchema($courierCode);
        $effectiveSchema = $customSchema ?? $schema;

        $validation = $this->validator->validateOrders($orders, $courierCode);
        $integrity = $this->validator->validateSchemaIntegrity($courierCode);

        $headers = $effectiveSchema->headers();
        $sampleRows = [];
        foreach ($orders->take(5) as $order) {
            $sampleRows[] = array_map(
                fn (CourierCsvColumn $col) => $this->resolveOrderFieldValue($order, $col->field),
                $effectiveSchema->columns,
            );
        }

        $sampleCsv = $this->buildCsv($headers, $sampleRows);
        $encoding = $this->encodingChecker->check($sampleCsv);

        $passing = array_filter($validation['orders'] ?? [], fn ($o) => $o['valid']);
        $failing = array_filter($validation['orders'] ?? [], fn ($o) => ! $o['valid']);

        return [
            'mode' => 'test',
            'courier_code' => strtoupper($courierCode),
            'schema' => $effectiveSchema->toArray(),
            'using_custom_template' => $customSchema !== null,
            'validation' => $validation,
            'schema_integrity' => $integrity,
            'encoding' => $encoding,
            'summary' => [
                'total' => $validation['total'] ?? $orders->count(),
                'valid' => $validation['valid_count'] ?? 0,
                'invalid' => $validation['invalid_count'] ?? 0,
                'pass_rate' => $validation['total'] ?? 0 > 0
                    ? round(($validation['valid_count'] ?? 0) / $validation['total'] * 100, 1)
                    : 0,
            ],
            'passing_orders' => array_values(array_map(fn ($o) => [
                'order_id' => $o['order_id'],
                'order_number' => $o['order_number'],
            ], $passing)),
            'failing_orders' => array_values(array_map(fn ($o) => [
                'order_id' => $o['order_id'],
                'order_number' => $o['order_number'],
                'missing_fields' => $o['missing_fields'],
                'address_errors' => $o['address_errors'] ?? [],
                'weight_errors' => $o['weight_errors'] ?? [],
                'suggestions' => $o['suggestions'] ?? [],
            ], $failing)),
            'can_export' => $validation['valid'] && $integrity['valid'] && $encoding['valid'],
        ];
    }

    /**
     * Run validation against raw CSV content (e.g. uploaded file).
     *
     * @return array<string, mixed>
     */
    public function testCsvContent(string $csvContent, string $courierCode): array
    {
        $encoding = $this->encodingChecker->check($csvContent);

        $normalized = $this->encodingChecker->normalize($csvContent);

        $rows = $this->parseCsv($normalized);

        if ($rows === []) {
            return [
                'mode' => 'test-csv',
                'courier_code' => strtoupper($courierCode),
                'encoding' => $encoding,
                'valid' => false,
                'errors' => ['CSV file is empty or could not be parsed.'],
                'row_count' => 0,
            ];
        }

        $headers = array_shift($rows);

        $schema = $this->schemas->resolve($courierCode);
        $customSchema = $this->templates->resolveSchema($courierCode);
        $effectiveSchema = $customSchema ?? $schema;
        $expectedHeaders = $effectiveSchema->headers();

        $headerMatch = $this->compareHeaders($headers, $expectedHeaders);

        $missingColumns = [];
        $extraColumns = [];

        foreach ($expectedHeaders as $expected) {
            if (! in_array($expected, $headers, true)) {
                $missingColumns[] = $expected;
            }
        }

        foreach ($headers as $actual) {
            if (! in_array($actual, $expectedHeaders, true)) {
                $extraColumns[] = $actual;
            }
        }

        $rowErrors = [];
        $validRowCount = 0;

        foreach ($rows as $i => $row) {
            $rowErrors[] = [
                'row_number' => $i + 2,
                'column_count' => count($row),
                'expected_column_count' => count($expectedHeaders),
                'column_mismatch' => count($row) !== count($expectedHeaders),
            ];

            if (count($row) === count($expectedHeaders)) {
                $validRowCount++;
            }
        }

        return [
            'mode' => 'test-csv',
            'courier_code' => strtoupper($courierCode),
            'encoding' => $encoding,
            'using_custom_template' => $customSchema !== null,
            'headers' => [
                'actual' => $headers,
                'expected' => $expectedHeaders,
                'match' => $headerMatch,
                'missing' => $missingColumns,
                'extra' => $extraColumns,
            ],
            'row_count' => count($rows),
            'valid_row_count' => $validRowCount,
            'column_mismatches' => array_values(array_filter($rowErrors, fn ($r) => $r['column_mismatch'])),
            'valid' => $encoding['valid'] && $headerMatch && $validRowCount === count($rows) && $missingColumns === [],
        ];
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function buildCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $row));
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $content): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param array<int, string> $a
     * @param array<int, string> $b
     */
    private function compareHeaders(array $a, array $b): bool
    {
        $a = array_map(fn ($v) => trim($v), $a);
        $b = array_map(fn ($v) => trim($v), $b);

        return $a === $b;
    }

    private function resolveOrderFieldValue(Order $order, string $field): mixed
    {
        return match ($field) {
            'order_number' => $order->order_number,
            'receiver_name' => $order->receiver_name,
            'receiver_phone' => $order->receiver_phone,
            'phone_number' => $order->receiver_phone,
            'receiver_address' => $order->receiver_address,
            'complete_address' => trim(implode(', ', array_filter([
                $order->receiver_address,
                $order->barangay,
                $order->city,
                $order->state,
                $order->postal_code,
            ]))),
            'province' => $order->state,
            'state' => $order->state,
            'city' => $order->city,
            'barangay' => $order->barangay,
            'postal_code' => $order->postal_code,
            'landmark' => $order->landmark,
            'nearest_landmark' => $order->nearest_landmark,
            'product_name' => $order->product?->name ?? $order->shopItems->first()?->product_name ?? '',
            'quantity' => $order->quantity,
            'cod_amount' => $order->cod_amount,
            'total_amount' => $order->total_amount,
            'item_value' => $order->unit_price,
            'remarks' => $order->remarks ?? '',
            'sender_name' => config('services.shop.sender_name', ''),
            'sender_phone' => config('services.shop.sender_phone', ''),
            'sender_address' => config('services.shop.sender_address', ''),
            'sender_province' => config('services.shop.sender_province', ''),
            'sender_city' => config('services.shop.sender_city', ''),
            'weight_kg' => $order->shopItems->sum(fn ($item) => (
                ($item->variant?->effective_weight ?? $item->product?->weight_grams ?? 0) * $item->quantity
            )) / 1000,
            default => '',
        };
    }
}
