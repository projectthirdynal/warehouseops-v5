<?php

use App\Services\Finance\SupplierInvoiceCalculator;

describe('deriveFromTotal', function () {
    it('returns zero tax when tax rate is zero', function () {
        $result = SupplierInvoiceCalculator::deriveFromTotal(
            totalAmount: 1000.00,
            taxRate: 0,
        );

        expect($result['subtotal'])->toBe(1000.0);
        expect($result['tax_amount'])->toBe(0.0);
    });

    it('derives subtotal and tax from total TTC', function () {
        $result = SupplierInvoiceCalculator::deriveFromTotal(
            totalAmount: 1120.00,
            taxRate: 12,
        );

        // 1120 / 1.12 = 1000, tax = 120
        expect($result['subtotal'])->toBe(1000.0);
        expect($result['tax_amount'])->toBe(120.0);
    });

    it('handles 20% VAT correctly', function () {
        $result = SupplierInvoiceCalculator::deriveFromTotal(
            totalAmount: 1200.00,
            taxRate: 20,
        );

        // 1200 / 1.20 = 1000, tax = 200
        expect($result['subtotal'])->toBe(1000.0);
        expect($result['tax_amount'])->toBe(200.0);
    });

    it('returns zero tax when total amount is zero', function () {
        $result = SupplierInvoiceCalculator::deriveFromTotal(
            totalAmount: 0,
            taxRate: 12,
        );

        expect($result['subtotal'])->toBe(0.0);
        expect($result['tax_amount'])->toBe(0.0);
    });

    it('rounds to two decimals', function () {
        $result = SupplierInvoiceCalculator::deriveFromTotal(
            totalAmount: 999.99,
            taxRate: 12,
        );

        // 999.99 / 1.12 = 892.848... ≈ 892.85
        // tax = 999.99 - 892.85 = 107.14
        expect($result['subtotal'])->toBe(892.85);
        expect($result['tax_amount'])->toBe(107.14);
    });
});
