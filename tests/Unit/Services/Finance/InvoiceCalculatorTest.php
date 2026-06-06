<?php

use App\Services\Finance\InvoiceCalculator;

beforeEach(function () {
    // No database needed — these are pure unit tests
});

describe('calculateLineTotals', function () {
    it('computes basic totals without discount or tax', function () {
        $result = InvoiceCalculator::calculateLineTotals(
            qty: 2,
            unitPrice: 100.00,
            discountPct: 0,
            taxRate: 0,
        );

        expect($result['subtotal'])->toBe(200.0);
        expect($result['discount_amount'])->toBe(0.0);
        expect($result['total_ht'])->toBe(200.0);
        expect($result['tax_amount'])->toBe(0.0);
        expect($result['total_ttc'])->toBe(200.0);
    });

    it('applies discount before tax', function () {
        $result = InvoiceCalculator::calculateLineTotals(
            qty: 10,
            unitPrice: 50.00,
            discountPct: 10,
            taxRate: 12,
        );

        // subtotal = 500, discount = 50, total_ht = 450, tax = 54, ttc = 504
        expect($result['subtotal'])->toBe(500.0);
        expect($result['discount_amount'])->toBe(50.0);
        expect($result['total_ht'])->toBe(450.0);
        expect($result['tax_amount'])->toBe(54.0);
        expect($result['total_ttc'])->toBe(504.0);
    });

    it('handles 100% discount', function () {
        $result = InvoiceCalculator::calculateLineTotals(
            qty: 1,
            unitPrice: 100.00,
            discountPct: 100,
            taxRate: 12,
        );

        expect($result['subtotal'])->toBe(100.0);
        expect($result['discount_amount'])->toBe(100.0);
        expect($result['total_ht'])->toBe(0.0);
        expect($result['tax_amount'])->toBe(0.0);
        expect($result['total_ttc'])->toBe(0.0);
    });

    it('uses defaults of zero for optional rates', function () {
        $result = InvoiceCalculator::calculateLineTotals(
            qty: 1,
            unitPrice: 99.99,
        );

        expect($result['subtotal'])->toBe(99.99);
        expect($result['discount_amount'])->toBe(0.0);
        expect($result['tax_amount'])->toBe(0.0);
    });

    it('rounds to two decimals', function () {
        $result = InvoiceCalculator::calculateLineTotals(
            qty: 3,
            unitPrice: 33.333,
            discountPct: 7.777,
            taxRate: 11.111,
        );

        expect($result['subtotal'])->toBe(100.0);        // 3 * 33.333 = 99.999 ≈ 100
        expect($result['discount_amount'])->toBe(7.78); // 100 * 0.07777 ≈ 7.78
        expect($result['total_ht'])->toBe(92.22);       // 100 - 7.78 = 92.22
    });
});

