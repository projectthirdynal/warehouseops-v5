<?php

use Modules\Inventory\Models\DeadStock;
use Modules\Inventory\Models\Supply;
use Modules\Inventory\Models\SupplyStock;
use Modules\Inventory\Models\Warehouse;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;
use App\Models\SiteSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;

function makeDeadStockUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

function makeDsWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view dead stock automation index', function () {
    $user = makeDeadStockUser();

    actingAs($user)
        ->get(route('inventory.dead-stock-automation.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/DeadStockAutomation'));
});

// ─── API ────────────────────────────────────────────────────────────────────

test('api returns dashboard data', function () {
    $user = makeDeadStockUser();

    actingAs($user)
        ->get(route('inventory.dead-stock-automation.api'))
        ->assertOk()
        ->assertJsonStructure([
            'summary' => [
                'total_items', 'total_value', 'dead_count', 'dead_value',
                'non_moving_count', 'non_moving_value', 'slow_count', 'slow_value',
                'total_write_offs', 'total_write_off_value',
            ],
            'buckets',
            'by_warehouse',
            'top_dead_items',
            'items',
            'settings',
        ]);
});

// ─── Supply classified as dead ──────────────────────────────────────────────

test('supply with old last_movement_at is classified as dead', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_slow_days', 30);

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 50,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 20,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(120),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.dead-stock-automation.api'))
        ->assertOk();

    $items = collect($response->json('items'));
    $supplyItem = $items->firstWhere('sku', $supply->sku);

    expect($supplyItem)->not->toBeNull()
        ->and($supplyItem['bucket'])->toBe('dead')
        ->and($supplyItem['days_idle'])->toBeGreaterThanOrEqual(120)
        ->and($supplyItem['total_value'])->toEqual(1000);
});

// ─── Supply classified as non-moving ────────────────────────────────────────

test('supply with moderate idle period is classified as non-moving', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_slow_days', 30);

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 30,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(70),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.dead-stock-automation.api'))
        ->assertOk();

    $items = collect($response->json('items'));
    $supplyItem = $items->firstWhere('sku', $supply->sku);

    expect($supplyItem)->not->toBeNull()
        ->and($supplyItem['bucket'])->toBe('non_moving');
});

// ─── Supply classified as slow ──────────────────────────────────────────────

test('supply with short idle period is classified as slow', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_slow_days', 30);

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 20,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(40),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.dead-stock-automation.api'))
        ->assertOk();

    $items = collect($response->json('items'));
    $supplyItem = $items->firstWhere('sku', $supply->sku);

    expect($supplyItem)->not->toBeNull()
        ->and($supplyItem['bucket'])->toBe('slow');
});

// ─── Product dead stock ─────────────────────────────────────────────────────

test('product with old last_movement_at is classified as dead', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_slow_days', 30);

    $product = Product::factory()->create([
        'is_active' => true,
        'cost_price' => 100,
    ]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 15,
        'reserved_stock' => 3,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(150),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.dead-stock-automation.api'))
        ->assertOk();

    $items = collect($response->json('items'));
    $productItem = $items->firstWhere('sku', $product->sku);

    expect($productItem)->not->toBeNull()
        ->and($productItem['bucket'])->toBe('dead')
        ->and($productItem['total_value'])->toEqual(1500)
        ->and($productItem['available_stock'])->toBe(12);
});

// ─── Items with zero stock excluded ─────────────────────────────────────────

test('items with zero stock are excluded', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 50,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 0,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(200),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.dead-stock-automation.api'))
        ->assertOk();

    $items = collect($response->json('items'));
    expect($items->firstWhere('sku', $supply->sku))->toBeNull();
});

// ─── Scan auto-flags supplies as DEAD ───────────────────────────────────────

test('scan auto-flags dead supplies with stock_status DEAD', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_slow_days', 30);
    SiteSetting::set('dead_stock_auto_write_off', false);

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 50,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(120),
    ]);

    $response = actingAs($user)
        ->post(route('inventory.dead-stock-automation.scan.api'))
        ->assertOk();

    $supply->refresh();
    expect($supply->stock_status)->toBe(Supply::STATUS_DEAD);
});

// ─── Scan with auto write-off creates DeadStock entries ─────────────────────

test('scan with auto_write_off creates dead stock entries', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_slow_days', 30);
    SiteSetting::set('dead_stock_auto_write_off', true);

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 50,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(120),
    ]);

    actingAs($user)
        ->post(route('inventory.dead-stock-automation.scan.api'))
        ->assertOk();

    $entry = DeadStock::where('item_type', 'supply')
        ->where('supply_id', $supply->id)
        ->whereDate('created_at', today())
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->quantity)->toBe(10)
        ->and((float) $entry->total_value)->toEqual(500.0);
});

// ─── Settings update ────────────────────────────────────────────────────────

test('settings can be updated via api', function () {
    $user = makeDeadStockUser();

    actingAs($user)
        ->patch(route('inventory.dead-stock-automation.settings.api'), [
            'slow_days' => 45,
            'non_moving_days' => 75,
            'dead_days' => 120,
            'auto_write_off' => true,
            'notify_emails' => 'test@example.com',
            'notify_email_enabled' => true,
            'notify_in_app_enabled' => false,
            'min_value_threshold' => 100,
            'scan_frequency' => 'weekly',
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect((int) SiteSetting::get('dead_stock_slow_days'))->toBe(45)
        ->and((int) SiteSetting::get('dead_stock_dead_days'))->toBe(120)
        ->and((bool) SiteSetting::get('dead_stock_auto_write_off'))->toBeTrue()
        ->and((string) SiteSetting::get('dead_stock_scan_frequency'))->toBe('weekly');
});

// ─── CSV export ─────────────────────────────────────────────────────────────

test('csv export returns downloadable csv', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 50,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(120),
    ]);

    actingAs($user)
        ->get(route('inventory.dead-stock-automation.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

// ─── Min value threshold filters items ──────────────────────────────────────

test('min value threshold filters low-value items', function () {
    $user = makeDeadStockUser();
    $warehouse = makeDsWarehouse();

    SiteSetting::set('dead_stock_slow_days', 30);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_min_value_threshold', 500);

    $supply = Supply::factory()->create([
        'is_active' => true,
        'cost_price' => 10,
        'stock_status' => Supply::STATUS_MOVING,
        'stock_status_override' => false,
    ]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 0,
        'last_movement_at' => now()->subDays(120),
    ]);

    $response = actingAs($user)
        ->get(route('inventory.dead-stock-automation.api'))
        ->assertOk();

    $items = collect($response->json('items'));
    // 5 * 10 = 50, below threshold of 500
    expect($items->firstWhere('sku', $supply->sku))->toBeNull();
});

// ─── Manual trigger scan via web ────────────────────────────────────────────

test('manual trigger scan via web returns redirect with success', function () {
    $user = makeDeadStockUser();

    SiteSetting::set('dead_stock_slow_days', 30);
    SiteSetting::set('dead_stock_non_moving_days', 60);
    SiteSetting::set('dead_stock_dead_days', 90);
    SiteSetting::set('dead_stock_auto_write_off', false);

    actingAs($user)
        ->post(route('inventory.dead-stock-automation.scan'))
        ->assertRedirect()
        ->assertSessionHas('success');
});
