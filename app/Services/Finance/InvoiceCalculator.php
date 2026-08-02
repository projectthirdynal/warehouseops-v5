<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\InvoiceLine;

/**
 * Pure calculation service for invoice line totals and invoice-level aggregates.
 *
 * This module is a seam: controllers delegate financial math here,
 * and tests can assert against the calculator directly without HTTP overhead.
 */
class InvoiceCalculator
{
    /**
     * Compute derived financial fields for a single invoice line.
     *
     * @return array{
     *   subtotal: float,
     *   discount_amount: float,
     *   total_ht: float,
     *   tax_amount: float,
     *   total_ttc: float,
     * }
     */
    public static function calculateLineTotals(
        float $qty,
        float $unitPrice,
        float $discountPct = 0,
        float $taxRate = 0,
    ): array {
        $subtotal = $qty * $unitPrice;
        $discountAmount = $subtotal * ($discountPct / 100);
        $totalHt = $subtotal - $discountAmount;
        $taxAmount = $totalHt * ($taxRate / 100);
        $totalTtc = $totalHt + $taxAmount;

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_ht' => round($totalHt, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_ttc' => round($totalTtc, 2),
        ];
    }

    /**
     * Recalculate invoice-level aggregates from its lines and persist.
     */
    public static function recalculateInvoice(Invoice $invoice): void
    {
        $lines = $invoice->lines()->get();

        // subtotal = pre-discount sum of qty * unit_price
        $subtotal = $lines->sum(fn ($line) => (float) $line->qty * (float) $line->unit_price);
        $discountAmount = $lines->sum('discount_amount');
        $taxAmount = $lines->sum('tax_amount');
        $shippingAmount = (float) $invoice->shipping_amount;
        $totalAmount = $subtotal - $discountAmount + $taxAmount + $shippingAmount;

        $invoice->update([
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'amount_due' => round($totalAmount - (float) $invoice->amount_paid, 2),
        ]);
    }

    /**
     * Create an InvoiceLine record with derived fields computed.
     */
    public static function createLine(
        Invoice $invoice,
        array $data,
    ): InvoiceLine {
        $lineTaxRate = isset($data['tax_rate']) && (float) $data['tax_rate'] > 0
            ? (float) $data['tax_rate']
            : (float) ($invoice->tax_rate ?? config('finance.default_tax_rate', 0));

        $totals = self::calculateLineTotals(
            (float) $data['qty'],
            (float) $data['unit_price'],
            (float) ($data['discount_pct'] ?? 0),
            $lineTaxRate,
        );

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'position' => $data['position'] ?? 0,
            'description' => $data['description'],
            'qty' => $data['qty'],
            'unit_price' => $data['unit_price'],
            'tax_rate' => $lineTaxRate,
            'discount_pct' => $data['discount_pct'] ?? 0,
            'discount_amount' => $totals['discount_amount'],
            'tax_amount' => $totals['tax_amount'],
            'total_ht' => $totals['total_ht'],
            'total_ttc' => $totals['total_ttc'],
        ]);
    }
}
