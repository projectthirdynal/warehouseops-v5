<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\CourierExportRow;

/**
 * Generates automatic correction suggestions for common courier CSV validation errors.
 */
final class CourierCsvCorrectionSuggester
{
    public function __construct(
        private readonly CourierCsvPhoneValidator $phoneValidator,
        private readonly CourierCsvCodValidator $codValidator,
        private readonly CourierCsvWeightDimensionValidator $weightValidator,
    ) {}

    /**
     * Generate correction suggestions for an Order.
     *
     * @return array<string, mixed>
     */
    public function suggestForOrder(Order $order, string $courierCode): array
    {
        $suggestions = [];

        $phone = (string) $order->receiver_phone;
        $phoneValidation = $this->phoneValidator->validate($phone, $courierCode);
        if (! $phoneValidation['valid']) {
            $suggestions['phone_number'] = $this->suggestPhone($phone);
        }

        $cod = $this->codValidator->validateOrder($order, $courierCode);
        if (! $cod['valid']) {
            $suggestions['cod_amount'] = $cod['expected'];
        }

        $address = $this->suggestAddress($order);
        if ($address !== []) {
            $suggestions['address'] = $address;
        }

        $weight = $this->weightValidator->validateOrder($order, $courierCode);
        if (! $weight['valid']) {
            $suggestions['weight_kg'] = $weight['weight_kg'];
            $suggestions['max_weight_kg'] = $weight['max_weight_kg'];
        }

        return $suggestions;
    }

    /**
     * Generate correction suggestions for a CourierExportRow.
     *
     * @return array<string, mixed>
     */
    public function suggestForRow(CourierExportRow $row, string $courierCode): array
    {
        if ($row->relationLoaded('order') && $row->order) {
            $suggestions = $this->suggestForOrder($row->order, $courierCode);

            if ($row->phone_number && $suggestions['phone_number'] ?? null) {
                $suggestions['phone_number'] = $this->suggestPhone((string) $row->phone_number);
            }

            if ($row->cod_amount !== null && ($suggestions['cod_amount'] ?? null) !== null) {
                $suggestions['cod_amount'] = $this->suggestCodForRow($row);
            }

            return $suggestions;
        }

        $suggestions = [];

        if ($row->phone_number) {
            $phoneValidation = $this->phoneValidator->validate((string) $row->phone_number, $courierCode);
            if (! $phoneValidation['valid']) {
                $suggestions['phone_number'] = $this->suggestPhone((string) $row->phone_number);
            }
        }

        if ($row->cod_amount !== null) {
            $suggestions['cod_amount'] = $this->suggestCodForRow($row);
        }

        return $suggestions;
    }

    /**
     * Suggest a corrected phone number, or null if it cannot be normalized.
     */
    public function suggestPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = $this->phoneValidator->extractDigits($phone);

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            return '0'.substr($digits, 2);
        }

        if (strlen($digits) === 11 && (str_starts_with($digits, '09') || str_starts_with($digits, '0'))) {
            return $digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        return null;
    }

    /**
     * Suggest corrected address values from the order's available fields and customer address history.
     *
     * @return array<string, string|null>
     */
    public function suggestAddress(Order $order): array
    {
        $suggestions = [];

        if (blank($order->receiver_address)) {
            $suggestions['receiver_address'] = $this->bestAddressValue($order, 'receiver_address');
        }

        if (blank($order->state)) {
            $suggestions['state'] = $this->bestAddressValue($order, 'state');
        }

        if (blank($order->city)) {
            $suggestions['city'] = $this->bestAddressValue($order, 'city');
        }

        if (blank($order->barangay)) {
            $suggestions['barangay'] = $this->bestAddressValue($order, 'barangay');
        }

        return array_filter($suggestions, fn ($v) => $v !== null);
    }

    public function suggestCodForOrder(Order $order): ?float
    {
        return $this->codValidator->expectedCodForOrder($order);
    }

    public function suggestCodForRow(CourierExportRow $row): ?float
    {
        if ($row->relationLoaded('order') && $row->order) {
            return $this->suggestCodForOrder($row->order);
        }

        return null;
    }

    public function suggestWeight(Order $order, string $courierCode): ?float
    {
        $validation = $this->weightValidator->validateOrder($order, $courierCode);

        return $validation['weight_kg'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestBatch(iterable $ordersOrRows, string $courierCode): array
    {
        $results = [];

        foreach ($ordersOrRows as $item) {
            if ($item instanceof Order) {
                $results[] = [
                    'order_id' => $item->id,
                    'order_number' => $item->order_number,
                    'suggestions' => $this->suggestForOrder($item, $courierCode),
                ];
            } elseif ($item instanceof CourierExportRow) {
                $results[] = [
                    'row_id' => $item->id,
                    'row_number' => $item->row_number,
                    'order_id' => $item->order_id,
                    'suggestions' => $this->suggestForRow($item, $courierCode),
                ];
            }
        }

        return $results;
    }

    private function bestAddressValue(Order $order, string $field): ?string
    {
        $customer = $order->relationLoaded('customer') ? $order->customer : $order->customer()->first();

        if ($customer !== null) {
            $address = $customer->defaultAddress ?? $customer->addresses()->latest()->first();

            if ($address !== null) {
                $value = match ($field) {
                    'receiver_address' => $address->full_address ?? $address->street,
                    'state' => $address->province ?? $address->state,
                    'city' => $address->city,
                    'barangay' => $address->barangay,
                    default => null,
                };

                if (! blank($value)) {
                    return (string) $value;
                }
            }
        }

        $value = match ($field) {
            'receiver_address' => $order->receiver_address ?? $order->full_address ?? null,
            'state' => $order->state ?? $order->province ?? null,
            'city' => $order->city,
            'barangay' => $order->barangay,
            default => null,
        };

        return blank($value) ? null : (string) $value;
    }
}
