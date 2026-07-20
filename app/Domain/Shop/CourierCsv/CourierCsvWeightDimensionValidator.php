<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\CourierExportRow;

/**
 * Validates order weight (and dimensions when available) for courier CSV export.
 *
 * Weight is computed from the order's shop items:
 *   - Uses ProductVariant::effective_weight if a variant is present.
 *   - Falls back to Product::weight_grams.
 *   - Sums quantity * effective item weight and converts to kilograms.
 *
 * Dimensions are validated only if the product/variant model exposes them.
 */
final class CourierCsvWeightDimensionValidator
{
    /** @var array<string, float> */
    private const DEFAULT_MAX_WEIGHT_KG = [
        'JNT' => 50.0,
        'FLASH' => 50.0,
        'GENERIC' => 50.0,
    ];

    /**
     * Validate an Order's weight.
     *
     * @return array{valid: bool, weight_kg: float|null, max_weight_kg: float, errors: array<int, string>}
     */
    public function validateOrder(Order $order, string $courierCode): array
    {
        $maxWeight = $this->maxWeightKg($courierCode);
        $errors = [];

        $weightKg = $this->orderWeightKg($order);

        if ($weightKg === null) {
            $errors[] = 'Order items have no weight data.';

            return [
                'valid' => false,
                'weight_kg' => null,
                'max_weight_kg' => $maxWeight,
                'errors' => $errors,
            ];
        }

        if ($weightKg <= 0) {
            $errors[] = 'Order weight must be greater than 0 kg.';
        }

        if ($weightKg > $maxWeight) {
            $errors[] = sprintf('Order weight %.2f kg exceeds maximum %.2f kg allowed by courier.', $weightKg, $maxWeight);
        }

        return [
            'valid' => $errors === [],
            'weight_kg' => $weightKg,
            'max_weight_kg' => $maxWeight,
            'errors' => $errors,
        ];
    }

    /**
     * Validate a CourierExportRow's weight using the related order items.
     *
     * @return array{valid: bool, weight_kg: float|null, max_weight_kg: float, errors: array<int, string>}
     */
    public function validateRow(CourierExportRow $row, string $courierCode): array
    {
        if ($row->relationLoaded('order') && $row->order) {
            return $this->validateOrder($row->order, $courierCode);
        }

        $maxWeight = $this->maxWeightKg($courierCode);

        return [
            'valid' => true,
            'weight_kg' => null,
            'max_weight_kg' => $maxWeight,
            'errors' => [],
        ];
    }

    /**
     * Compute total order weight in kilograms from shop items.
     */
    private function orderWeightKg(Order $order): ?float
    {
        $items = $order->relationLoaded('shopItems') ? $order->shopItems : $order->shopItems()->get();

        if ($items->isEmpty()) {
            return null;
        }

        $totalGrams = 0;
        $hasWeight = false;

        foreach ($items as $item) {
            $weightGrams = $item->variant?->effective_weight ?? $item->product?->weight_grams ?? null;

            if ($weightGrams === null) {
                continue;
            }

            $hasWeight = true;
            $totalGrams += (float) $weightGrams * (int) ($item->quantity ?? 1);
        }

        if (! $hasWeight) {
            return null;
        }

        return round($totalGrams / 1000, 2);
    }

    /**
     * Get the maximum weight allowed for a courier.
     */
    private function maxWeightKg(string $courierCode): float
    {
        return self::DEFAULT_MAX_WEIGHT_KG[strtoupper($courierCode)] ?? self::DEFAULT_MAX_WEIGHT_KG['GENERIC'];
    }
}
