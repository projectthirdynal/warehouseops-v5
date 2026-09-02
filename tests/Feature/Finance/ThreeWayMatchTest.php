<?php

use Modules\Finance\Models\ThreeWayMatch;
use Modules\Finance\Services\ThreeWayMatchService;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Enums\GrnStatus;
use Modules\Procurement\Enums\PoStatus;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderItem;
use Modules\Procurement\Models\ReceivingReport;
use Modules\Procurement\Models\ReceivingReportItem;
use Modules\Procurement\Models\Supplier;
use Modules\Products\Models\Product;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\User;

use function Pest\Laravel\actingAs;

function twmUser(): User
{
    return User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
}

function twmSupplier(): Supplier
{
    return Supplier::create([
        'name' => 'TWM Test Supplier',
        'code' => 'TWM-'.uniqid(),
        'is_active' => true,
    ]);
}

function twmWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

function twmProduct(): Product
{
    return Product::factory()->create(['is_active' => true]);
}

function twmPo(Supplier $supplier, Warehouse $warehouse, User $user, float $total = 1000): PurchaseOrder
{
    $po = PurchaseOrder::create([
        'po_number' => 'PO-TWM-'.uniqid(),
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'status' => PoStatus::SENT,
        'currency_code' => 'PHP',
        'subtotal' => $total,
        'tax_amount' => 0,
        'total_amount' => $total,
        'created_by' => $user->id,
    ]);

    $product = twmProduct();
    $qty = 10;
    $unitPrice = $total / $qty;

    PurchaseOrderItem::create([
        'po_id' => $po->id,
        'product_id' => $product->id,
        'quantity_ordered' => $qty,
        'quantity_received' => 0,
        'unit_price' => $unitPrice,
        'tax_rate' => 0,
        'line_total' => $total,
    ]);

    return $po->fresh('items');
}

function twmGrn(PurchaseOrder $po, User $user, int $receivedQty = 10): ReceivingReport
{
    $grn = ReceivingReport::create([
        'grn_number' => 'GRN-TWM-'.uniqid(),
        'po_id' => $po->id,
        'warehouse_id' => $po->warehouse_id,
        'received_by' => $user->id,
        'received_at' => now(),
        'status' => GrnStatus::CONFIRMED,
        'confirmed_at' => now(),
    ]);

    foreach ($po->items as $item) {
        ReceivingReportItem::create([
            'grn_id' => $grn->id,
            'po_item_id' => $item->id,
            'quantity_received' => $receivedQty,
            'quantity_rejected' => 0,
            'condition' => 'GOOD',
        ]);
    }

    return $grn;
}

function twmSupplierInvoice(PurchaseOrder $po, float $total, ?User $user = null): SupplierInvoice
{
    $invoice = SupplierInvoice::create([
        'ref' => SupplierInvoice::generateRef(),
        'status' => 'VALIDATED',
        'po_id' => $po->id,
        'supplier_name' => $po->supplier->name,
        'date_invoice' => now()->toDateString(),
        'date_due' => now()->addDays(30)->toDateString(),
        'currency' => 'PHP',
        'subtotal' => $total,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'amount_due' => $total,
        'created_by' => $user?->id ?? twmUser()->id,
    ]);

    foreach ($po->items as $item) {
        SupplierInvoiceItem::create([
            'supplier_invoice_id' => $invoice->id,
            'po_item_id' => $item->id,
            'product_id' => $item->product_id,
            'quantity' => $item->quantity_ordered,
            'unit_price' => $item->unit_price,
            'tax_rate' => 0,
            'line_total' => $item->line_total,
            'position' => 0,
        ]);
    }

    return $invoice->fresh('items');
}

afterEach(function () {
    ThreeWayMatch::query()->delete();
});

test('three-way match dashboard renders', function () {
    actingAs(twmUser());
    $this->get('/finance/three-way-match')->assertOk();
});

test('run match with no GRN returns pending with mismatch', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user);

    $match = app(ThreeWayMatchService::class)->runMatch($po->id, null, $user->id);

    expect($match->status)->toBe('PENDING')
        ->and($match->match_level)->toBe('NONE')
        ->and($match->mismatches)->not->toBeNull();

    $hasMissingGrn = collect($match->mismatches)->contains(fn ($m) => $m['type'] === 'missing_grn');
    expect($hasMissingGrn)->toBeTrue();
});

test('run match with matching PO, GRN, and invoice returns matched', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user, 1000);
    twmGrn($po, $user, 10);
    $invoice = twmSupplierInvoice($po, 1000, $user);

    $match = app(ThreeWayMatchService::class)->runMatch($po->id, $invoice->id, $user->id);

    expect($match->status)->toBe('MATCHED')
        ->and($match->match_level)->toBe('FULL')
        ->and($match->mismatches)->toBeNull()
        ->and((float) $match->variance_amount)->toBe(0.0);
});

test('run match detects quantity short', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user, 1000);
    twmGrn($po, $user, 7); // Received 7 of 10 ordered

    $match = app(ThreeWayMatchService::class)->runMatch($po->id, null, $user->id);

    $hasQtyShort = collect($match->mismatches ?? [])->contains(fn ($m) => $m['type'] === 'quantity_short');
    expect($hasQtyShort)->toBeTrue();
});

test('run match detects total mismatch between PO and invoice', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user, 1000);
    twmGrn($po, $user, 10);
    $invoice = twmSupplierInvoice($po, 1200, $user); // Overcharged

    $match = app(ThreeWayMatchService::class)->runMatch($po->id, $invoice->id, $user->id);

    expect($match->status)->toBe('BLOCKED')
        ->and($match->mismatches)->not->toBeNull();

    $hasTotalMismatch = collect($match->mismatches)->contains(fn ($m) => $m['type'] === 'total_mismatch');
    expect($hasTotalMismatch)->toBeTrue();
});

test('run match detects line price mismatch', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user, 1000);
    twmGrn($po, $user, 10);

    // Create invoice with different unit price
    $invoice = SupplierInvoice::create([
        'ref' => SupplierInvoice::generateRef(),
        'status' => 'VALIDATED',
        'po_id' => $po->id,
        'supplier_name' => $supplier->name,
        'date_invoice' => now()->toDateString(),
        'currency' => 'PHP',
        'subtotal' => 1200,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total_amount' => 1200,
        'amount_due' => 1200,
        'created_by' => $user->id,
    ]);

    foreach ($po->items as $item) {
        SupplierInvoiceItem::create([
            'supplier_invoice_id' => $invoice->id,
            'po_item_id' => $item->id,
            'product_id' => $item->product_id,
            'quantity' => $item->quantity_ordered,
            'unit_price' => 120, // PO price is 100
            'tax_rate' => 0,
            'line_total' => 1200,
            'position' => 0,
        ]);
    }

    $match = app(ThreeWayMatchService::class)->runMatch($po->id, $invoice->id, $user->id);

    $hasPriceMismatch = collect($match->mismatches)->contains(fn ($m) => $m['type'] === 'line_price_mismatch');
    expect($hasPriceMismatch)->toBeTrue();
});

test('get stats returns correct counts', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();

    // Create a matched record
    $po1 = twmPo($supplier, $warehouse, $user, 500);
    twmGrn($po1, $user, 10); // Full receipt
    $inv1 = twmSupplierInvoice($po1, 500, $user);
    app(ThreeWayMatchService::class)->runMatch($po1->id, $inv1->id, $user->id);

    // Create a mismatched record
    $po2 = twmPo($supplier, $warehouse, $user, 800);
    twmGrn($po2, $user, 10); // Full receipt
    $inv2 = twmSupplierInvoice($po2, 808, $user); // 1% overcharge → MISMATCH
    app(ThreeWayMatchService::class)->runMatch($po2->id, $inv2->id, $user->id);

    $stats = app(ThreeWayMatchService::class)->getStats();

    expect($stats['total'])->toBe(2)
        ->and($stats['matched'])->toBe(1)
        ->and($stats['mismatch'])->toBeGreaterThanOrEqual(1);
});

test('get match detail returns line comparison', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user, 1000);
    twmGrn($po, $user, 10);
    $invoice = twmSupplierInvoice($po, 1000, $user);

    $match = app(ThreeWayMatchService::class)->runMatch($po->id, $invoice->id, $user->id);
    $detail = app(ThreeWayMatchService::class)->getMatchDetail($match->id);

    expect($detail['line_comparison'])->toHaveCount(1)
        ->and($detail['line_comparison'][0]['po_quantity'])->toBe(10)
        ->and($detail['line_comparison'][0]['grn_quantity'])->toBe(10)
        ->and($detail['line_comparison'][0]['invoice_quantity'])->toBe(10);
});

test('get eligible pos returns unmatched sent POs', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user);

    $eligible = app(ThreeWayMatchService::class)->getEligiblePos();

    expect($eligible)->not->toBeEmpty();
    $found = $eligible->firstWhere('id', $po->id);
    expect($found)->not->toBeNull();
});

test('run match via service creates three_way_match record', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user, 500);
    twmGrn($po, $user, 5);
    $invoice = twmSupplierInvoice($po, 500, $user);

    app(ThreeWayMatchService::class)->runMatch($po->id, $invoice->id, $user->id);

    expect(ThreeWayMatch::where('po_id', $po->id)->exists())->toBeTrue();
});

test('re-running match updates existing record', function () {
    $user = twmUser();
    $supplier = twmSupplier();
    $warehouse = twmWarehouse();
    $po = twmPo($supplier, $warehouse, $user, 500);
    twmGrn($po, $user, 5);
    $invoice = twmSupplierInvoice($po, 500, $user);

    $match1 = app(ThreeWayMatchService::class)->runMatch($po->id, $invoice->id, $user->id);
    $match2 = app(ThreeWayMatchService::class)->runMatch($po->id, $invoice->id, $user->id);

    expect($match1->id)->toBe($match2->id)
        ->and(ThreeWayMatch::where('po_id', $po->id)->count())->toBe(1);
});
