<?php

namespace App\Services\Finance;

/**
 * Calculation service for supplier invoices.
 *
 * Unlike customer invoices (which compute line-by-line),
 * supplier invoices accept a total TTC amount and derive
 * subtotal / tax by backing out the tax rate.
 */
class SupplierInvoiceCalculator
{
    /**
     * Derive subtotal and tax_amount from a total TTC amount and tax rate.
     *
     * @return array{subtotal: float, tax_amount: float}
     */
    public static function deriveFromTotal(
        float $totalAmount,
        float $taxRate = 0,
    ): array {
        if ($taxRate <= 0 || $totalAmount <= 0) {
            return [
                'subtotal' => round($totalAmount, 2),
                'tax_amount' => 0.0,
            ];
        }

        $subtotal = $totalAmount / (1 + $taxRate / 100);
        $taxAmount = $totalAmount - $subtotal;

        return [
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
        ];
    }
}
