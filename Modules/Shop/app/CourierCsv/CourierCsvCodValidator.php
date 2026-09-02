<?php

declare(strict_types=1);

namespace Modules\Shop\CourierCsv;

use Illuminate\Support\Collection;
use Modules\Orders\Models\Order;
use Modules\Shop\Models\CourierExportRow;

/**
 * Validates that COD (cash-on-delivery) amount is consistent with the order total.
 *
 * For COD orders the amount payable on delivery should equal the computed order total.
 * Allow a small tolerance for floating point comparison.
 */
final class CourierCsvCodValidator
{
    public function __construct(private readonly CourierCsvValidationConfig $config) {}

    private const TOLERANCE = 0.01;

    /**
     * Validate an Order's cod_amount against its expected order total.
     *
     * Expected total is derived, in order of preference:
     *   1. Sum of shop item line_totals (multi-item carts)
     *   2. Order total_amount field
     *   3. quantity * unit_price for single-item orders
     *
     * @return array{valid: bool, expected: float|null, actual: float|null, error: string|null}
     */
    public function validateOrder(Order $order, ?string $courierCode = null): array
    {
        $rules = $this->config->get($courierCode ?? 'DEFAULT')['cod_amount'] ?? [];

        if (($rules['enabled'] ?? true) === false) {
            return [
                'valid' => true,
                'expected' => null,
                'actual' => $order->cod_amount !== null ? (float) $order->cod_amount : null,
                'error' => null,
            ];
        }

        $tolerance = (float) ($rules['tolerance'] ?? self::TOLERANCE);
        $actual = $order->cod_amount !== null ? (float) $order->cod_amount : null;

        if ($actual === null) {
            return [
                'valid' => false,
                'expected' => null,
                'actual' => null,
                'error' => 'COD amount is required.',
            ];
        }

        if ($actual < 0) {
            return [
                'valid' => false,
                'expected' => null,
                'actual' => $actual,
                'error' => 'COD amount cannot be negative.',
            ];
        }

        $expected = $this->expectedCodForOrder($order);

        if ($expected === null) {
            return [
                'valid' => true,
                'expected' => null,
                'actual' => $actual,
                'error' => null,
            ];
        }

        if (abs($expected - $actual) > $tolerance) {
            return [
                'valid' => false,
                'expected' => $expected,
                'actual' => $actual,
                'error' => sprintf('COD amount %.2f does not match expected total %.2f.', $actual, $expected),
            ];
        }

        return [
            'valid' => true,
            'expected' => $expected,
            'actual' => $actual,
            'error' => null,
        ];
    }

    /**
     * Validate a CourierExportRow's cod_amount against its related order.
     *
     * @return array{valid: bool, expected: float|null, actual: float|null, error: string|null}
     */
    public function validateRow(CourierExportRow $row, ?string $courierCode = null): array
    {
        $actual = $row->cod_amount !== null ? (float) $row->cod_amount : null;

        if ($actual === null) {
            return [
                'valid' => false,
                'expected' => null,
                'actual' => null,
                'error' => 'COD amount is required.',
            ];
        }

        if ($actual < 0) {
            return [
                'valid' => false,
                'expected' => null,
                'actual' => $actual,
                'error' => 'COD amount cannot be negative.',
            ];
        }

        if ($row->relationLoaded('order') && $row->order) {
            return $this->validateOrder($row->order, $courierCode);
        }

        return [
            'valid' => true,
            'expected' => null,
            'actual' => $actual,
            'error' => null,
        ];
    }

    /**
     * Calculate the expected COD amount for an order.
     */
    public function expectedCodForOrder(Order $order): ?float
    {
        if ($order->relationLoaded('shopItems') && $order->shopItems->isNotEmpty()) {
            return (float) $order->shopItems->sum('line_total');
        }

        if (! $order->relationLoaded('shopItems')) {
            $items = $order->shopItems()->get();
            if ($items->isNotEmpty()) {
                return (float) $items->sum('line_total');
            }
        }

        if ($order->total_amount !== null) {
            return (float) $order->total_amount;
        }

        if ($order->quantity !== null && $order->unit_price !== null) {
            return (float) ($order->quantity * $order->unit_price);
        }

        return null;
    }

    /**
     * Validate a raw COD amount against an expected total derived from items.
     *
     * @param  Collection<int, object{quantity: int, unit_price: float, line_total?: float, discount_amount?: float}>|null  $items
     * @return array{valid: bool, expected: float|null, actual: float|null, error: string|null}
     */
    public function validate(?float $codAmount, ?float $expectedAmount = null, ?Collection $items = null, ?string $courierCode = null): array
    {
        $rules = $this->config->get($courierCode ?? 'DEFAULT')['cod_amount'] ?? [];

        if (($rules['enabled'] ?? true) === false) {
            return [
                'valid' => true,
                'expected' => $expectedAmount,
                'actual' => $codAmount,
                'error' => null,
            ];
        }

        $tolerance = (float) ($rules['tolerance'] ?? self::TOLERANCE);
        if ($codAmount === null) {
            return [
                'valid' => false,
                'expected' => $expectedAmount,
                'actual' => null,
                'error' => 'COD amount is required.',
            ];
        }

        if ($codAmount < 0) {
            return [
                'valid' => false,
                'expected' => $expectedAmount,
                'actual' => $codAmount,
                'error' => 'COD amount cannot be negative.',
            ];
        }

        if ($items !== null && $items->isNotEmpty()) {
            $expectedAmount = (float) $items->sum(fn ($item) => $item->line_total ?? ($item->quantity * $item->unit_price - ($item->discount_amount ?? 0)));
        }

        if ($expectedAmount === null) {
            return [
                'valid' => true,
                'expected' => null,
                'actual' => $codAmount,
                'error' => null,
            ];
        }

        if (abs($expectedAmount - $codAmount) > $tolerance) {
            return [
                'valid' => false,
                'expected' => $expectedAmount,
                'actual' => $codAmount,
                'error' => sprintf('COD amount %.2f does not match expected total %.2f.', $codAmount, $expectedAmount),
            ];
        }

        return [
            'valid' => true,
            'expected' => $expectedAmount,
            'actual' => $codAmount,
            'error' => null,
        ];
    }
}
