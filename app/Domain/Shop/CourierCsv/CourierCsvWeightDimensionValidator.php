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
    public function __construct(private readonly CourierCsvValidationConfig $config) {}

    /**
     * Validate an Order's weight.
     *
     * @return array{valid: bool, weight_kg: float|null, max_weight_kg: float, errors: array<int, string>}
     */
    public function validateOrder(Order $order, string $courierCode): array
    {
        $rules = $this->config->get(strtoupper($courierCode))['weight'] ?? [];

        if (($rules['enabled'] ?? true) === false) {
            return [
                'valid' => true,
                'weight_kg' => null,
                'max_weight_kg' => (float) ($rules['max_weight_kg'] ?? 50.0),
                'errors' => [],
            ];
        }

        $maxWeight = (float) ($rules['max_weight_kg'] ?? 50.0);
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

        $this->validateDimensions($order, $errors, $rules);

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

        $rules = $this->config->get(strtoupper($courierCode))['weight'] ?? [];

        return [
            'valid' => true,
            'weight_kg' => null,
            'max_weight_kg' => (float) ($rules['max_weight_kg'] ?? 50.0),
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
     * Validate item dimensions when product/variant exposes them.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $errors
     */
    private function validateDimensions(Order $order, array &$errors, array $rules): void
    {
        $dimensionRules = $this->config->get('DEFAULT')['dimensions'] ?? [];

        if (($dimensionRules['enabled'] ?? false) === false) {
            return;
        }

        $items = $order->relationLoaded('shopItems') ? $order->shopItems : $order->shopItems()->get();

        foreach ($items as $item) {
            $model = $item->variant ?? $item->product;

            if ($model === null) {
                continue;
            }

            foreach (['length_mm', 'width_mm', 'height_mm'] as $dim) {
                if (! isset($model->{$dim})) {
                    continue;
                }

                $value = (float) $model->{$dim};
                $maxKey = str_replace('_mm', '_cm', $dim);
                $maxCm = (float) ($dimensionRules[$maxKey] ?? 100.0);

                if ($value <= 0) {
                    $errors[] = "Dimension {$dim} must be greater than 0.";
                } elseif ($value / 10 > $maxCm) {
                    $errors[] = "Dimension {$dim} ({$value} mm) exceeds maximum {$maxCm} cm.";
                }
            }
        }
    }
}
