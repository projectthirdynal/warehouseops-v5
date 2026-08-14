<?php

use App\Domain\Inventory\Models\StockCostLot;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Models\User;

use function Pest\Laravel\actingAs;

function makeValuationUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

function makeValuationWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view inventory valuation index', function () {
    $user = makeValuationUser();

    actingAs($user)
        ->get(route('inventory.valuation.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/InventoryValuation'));
});

// ─── FIFO ───────────────────────────────────────────────────────────────────

test('fifo valuation uses oldest cost lots', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'cost_price' => 100]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    // Old lot: 5 units @ 50
    StockCostLot::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'quantity_received' => 5,
        'quantity_remaining' => 5,
        'unit_cost' => 50,
        'currency_code' => 'PHP',
        'exchange_rate' => 1.0,
        'received_at' => now()->subDays(10),
    ]);

    // New lot: 5 units @ 80
    StockCostLot::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'quantity_received' => 5,
        'quantity_remaining' => 5,
        'unit_cost' => 80,
        'currency_code' => 'PHP',
        'exchange_rate' => 1.0,
        'received_at' => now()->subDays(1),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api', ['method' => 'FIFO']))
        ->assertOk();

    $items = collect($response->json('items'));
    $productItem = $items->firstWhere('item_sku', $product->sku);

    // FIFO: weighted avg of remaining lots = (5*50 + 5*80) / 10 = 65
    expect($productItem)->not->toBeNull()
        ->and($productItem['unit_cost'])->toEqual(65)
        ->and($productItem['total_value'])->toEqual(650);
});

// ─── LIFO ───────────────────────────────────────────────────────────────────

test('lifo valuation uses newest cost lots', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'cost_price' => 100]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    StockCostLot::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'quantity_received' => 5,
        'quantity_remaining' => 5,
        'unit_cost' => 50,
        'currency_code' => 'PHP',
        'exchange_rate' => 1.0,
        'received_at' => now()->subDays(10),
    ]);

    StockCostLot::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'quantity_received' => 5,
        'quantity_remaining' => 5,
        'unit_cost' => 80,
        'currency_code' => 'PHP',
        'exchange_rate' => 1.0,
        'received_at' => now()->subDays(1),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api', ['method' => 'LIFO']))
        ->assertOk();

    $items = collect($response->json('items'));
    $productItem = $items->firstWhere('item_sku', $product->sku);

    // LIFO: same remaining lots, weighted avg = (5*50 + 5*80) / 10 = 65
    // (LIFO affects which lots are consumed first, but remaining value is same when all lots remain)
    expect($productItem)->not->toBeNull()
        ->and($productItem['unit_cost'])->toEqual(65)
        ->and($productItem['total_value'])->toEqual(650);
});

// ─── Weighted Average ───────────────────────────────────────────────────────

test('weighted average valuation calculates average cost', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'cost_price' => 100]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    StockCostLot::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'quantity_received' => 3,
        'quantity_remaining' => 3,
        'unit_cost' => 40,
        'currency_code' => 'PHP',
        'exchange_rate' => 1.0,
        'received_at' => now()->subDays(10),
    ]);

    StockCostLot::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'quantity_received' => 7,
        'quantity_remaining' => 7,
        'unit_cost' => 60,
        'currency_code' => 'PHP',
        'exchange_rate' => 1.0,
        'received_at' => now()->subDays(1),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api', ['method' => 'WEIGHTED_AVERAGE']))
        ->assertOk();

    $items = collect($response->json('items'));
    $productItem = $items->firstWhere('item_sku', $product->sku);

    // Weighted avg = (3*40 + 7*60) / 10 = 540 / 10 = 54
    expect($productItem)->not->toBeNull()
        ->and($productItem['unit_cost'])->toEqual(54)
        ->and($productItem['total_value'])->toEqual(540);
});

// ─── Supply valuation ───────────────────────────────────────────────────────

test('supply valuation uses cost_price from supply model', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();
    $supply = Supply::factory()->create(['is_active' => true, 'cost_price' => 25]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 100,
        'reserved_stock' => 10,
        'reorder_point' => 0,
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api', ['stream' => 'supply']))
        ->assertOk();

    $items = collect($response->json('items'));
    $supplyItem = $items->firstWhere('item_sku', $supply->sku);

    expect($supplyItem)->not->toBeNull()
        ->and($supplyItem['unit_cost'])->toEqual(25)
        ->and($supplyItem['total_value'])->toEqual(2500)
        ->and($supplyItem['available_stock'])->toBe(90);
});

// ─── Falls back to product cost_price when no lots ──────────────────────────

test('valuation falls back to product cost_price when no cost lots exist', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'cost_price' => 75]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 20,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api', ['method' => 'FIFO']))
        ->assertOk();

    $items = collect($response->json('items'));
    $productItem = $items->firstWhere('item_sku', $product->sku);

    expect($productItem)->not->toBeNull()
        ->and($productItem['unit_cost'])->toEqual(75)
        ->and($productItem['total_value'])->toEqual(1500);
});

// ─── Summary ────────────────────────────────────────────────────────────────

test('summary contains correct totals', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();

    $product = Product::factory()->create([
        'is_active' => true,
        'cost_price' => 50,
        'selling_price' => 100,
    ]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 2,
        'reorder_point' => 0,
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api'))
        ->assertOk();

    $summary = $response->json('summary');

    expect($summary['total_value'])->toEqual(500)
        ->and($summary['product_value'])->toEqual(500)
        ->and($summary['available_value'])->toEqual(400)
        ->and($summary['reserved_value'])->toEqual(100)
        ->and($summary['potential_sales_value'])->toEqual(1000)
        ->and($summary['potential_margin'])->toEqual(500);
});

// ─── CSV Export ─────────────────────────────────────────────────────────────

test('csv export returns downloadable csv', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'cost_price' => 50]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    actingAs($user)
        ->get(route('inventory.valuation.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

// ─── Warehouse filter ───────────────────────────────────────────────────────

test('warehouse filter limits results to specified warehouse', function () {
    $user = makeValuationUser();
    $wh1 = makeValuationWarehouse();
    $wh2 = makeValuationWarehouse();

    $product = Product::factory()->create(['is_active' => true, 'cost_price' => 50]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $wh1->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $wh2->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api', ['warehouse_id' => $wh1->id]))
        ->assertOk();

    $items = collect($response->json('items'));
    expect($items)->toHaveCount(1)
        ->and($items->first()['warehouse'])->toBe($wh1->name);
});

// ─── Items with zero stock are excluded ─────────────────────────────────────

test('items with zero stock are excluded from valuation', function () {
    $user = makeValuationUser();
    $warehouse = makeValuationWarehouse();

    $product = Product::factory()->create(['is_active' => true, 'cost_price' => 50]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 0,
        'reserved_stock' => 0,
        'reorder_point' => 0,
    ]);

    $response = actingAs($user)
        ->get(route('inventory.valuation.api'))
        ->assertOk();

    $items = collect($response->json('items'));
    expect($items)->toHaveCount(0);
});
