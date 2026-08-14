<?php

use App\Domain\Inventory\Models\StockAlert;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Models\SiteSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;

function makeReorderUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

function makeReorderWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view reorder alerts index', function () {
    $user = makeReorderUser();

    actingAs($user)
        ->get(route('inventory.reorder-alerts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/ReorderPointAlerts'));
});

// ─── Scan ───────────────────────────────────────────────────────────────────

test('scan creates alert for product below reorder point', function () {
    $user = makeReorderUser();
    $warehouse = makeReorderWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    actingAs($user)
        ->post(route('inventory.reorder-alerts.scan'))
        ->assertRedirect();

    $alert = StockAlert::where('alert_type', StockAlert::TYPE_LOW_STOCK)
        ->where('stockable_type', 'App\\Domain\\Product\\Models\\ProductStock')
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->status)->toBe(StockAlert::STATUS_OPEN)
        ->and($alert->current_stock)->toBe(5)
        ->and($alert->reorder_point)->toBe(10);
});

test('scan creates alert for supply below reorder point', function () {
    $user = makeReorderUser();
    $warehouse = makeReorderWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 3,
        'reserved_stock' => 0,
        'reorder_point' => 8,
    ]);

    actingAs($user)
        ->post(route('inventory.reorder-alerts.scan'))
        ->assertRedirect();

    expect(
        StockAlert::where('alert_type', StockAlert::TYPE_LOW_STOCK)
            ->where('stockable_type', 'App\\Domain\\Inventory\\Models\\SupplyStock')
            ->exists()
    )->toBeTrue();
});

test('scan does not create alert when stock is above reorder point', function () {
    $user = makeReorderUser();
    $warehouse = makeReorderWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 50,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    actingAs($user)
        ->post(route('inventory.reorder-alerts.scan'))
        ->assertRedirect();

    expect(
        StockAlert::where('alert_type', StockAlert::TYPE_LOW_STOCK)
            ->where('stockable_type', 'App\\Domain\\Product\\Models\\ProductStock')
            ->exists()
    )->toBeFalse();
});

test('scan resolves stale alert when stock is replenished', function () {
    $user = makeReorderUser();
    $warehouse = makeReorderWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    $stock = ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    $alert = StockAlert::create([
        'stockable_type' => 'App\\Domain\\Product\\Models\\ProductStock',
        'stockable_id' => $stock->id,
        'warehouse_id' => $warehouse->id,
        'alert_type' => StockAlert::TYPE_LOW_STOCK,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
        'suggested_reorder_qty' => 25,
        'status' => StockAlert::STATUS_OPEN,
    ]);

    // Replenish stock
    $stock->update(['current_stock' => 50]);

    actingAs($user)
        ->post(route('inventory.reorder-alerts.scan'))
        ->assertRedirect();

    $alert->refresh();
    expect($alert->status)->toBe(StockAlert::STATUS_RESOLVED);
});

test('scan does not create duplicate alert for same stock item', function () {
    $user = makeReorderUser();
    $warehouse = makeReorderWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    $stock = ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    // First scan
    actingAs($user)->post(route('inventory.reorder-alerts.scan'))->assertRedirect();

    // Second scan
    actingAs($user)->post(route('inventory.reorder-alerts.scan'))->assertRedirect();

    $count = StockAlert::where('alert_type', StockAlert::TYPE_LOW_STOCK)
        ->where('stockable_type', 'App\\Domain\\Product\\Models\\ProductStock')
        ->where('stockable_id', $stock->id)
        ->whereIn('status', [StockAlert::STATUS_OPEN, StockAlert::STATUS_ACKNOWLEDGED])
        ->count();

    expect($count)->toBe(1);
});

// ─── Acknowledge ────────────────────────────────────────────────────────────

test('warehouse user can acknowledge an open alert', function () {
    $user = makeReorderUser();
    $warehouse = makeReorderWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    $stock = ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    $alert = StockAlert::create([
        'stockable_type' => 'App\\Domain\\Product\\Models\\ProductStock',
        'stockable_id' => $stock->id,
        'warehouse_id' => $warehouse->id,
        'alert_type' => StockAlert::TYPE_LOW_STOCK,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
        'suggested_reorder_qty' => 25,
        'status' => StockAlert::STATUS_OPEN,
    ]);

    actingAs($user)
        ->post(route('inventory.reorder-alerts.acknowledge', $alert), ['notes' => 'PO placed'])
        ->assertRedirect();

    $alert->refresh();
    expect($alert->status)->toBe(StockAlert::STATUS_ACKNOWLEDGED)
        ->and($alert->acknowledged_by)->toBe($user->id)
        ->and($alert->notes)->toBe('PO placed');
});

// ─── Settings ───────────────────────────────────────────────────────────────

test('admin can update reorder alert settings', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    actingAs($admin)
        ->patch(route('inventory.reorder-alerts.settings'), [
            'notify_emails' => 'buyer@test.com',
            'notify_roles' => ['warehouse', 'supervisor'],
            'notify_email_enabled' => true,
            'notify_in_app_enabled' => true,
            'scan_frequency' => 'daily',
            'reorder_multiplier' => 3,
        ])
        ->assertRedirect();

    expect(SiteSetting::get('reorder_notify_emails'))->toBe('buyer@test.com')
        ->and(SiteSetting::get('reorder_multiplier'))->toBe('3');
});

// ─── API ────────────────────────────────────────────────────────────────────

test('api returns alerts and summary', function () {
    $user = makeReorderUser();
    $warehouse = makeReorderWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    $stock = ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    StockAlert::create([
        'stockable_type' => 'App\\Domain\\Product\\Models\\ProductStock',
        'stockable_id' => $stock->id,
        'warehouse_id' => $warehouse->id,
        'alert_type' => StockAlert::TYPE_LOW_STOCK,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 10,
        'suggested_reorder_qty' => 25,
        'status' => StockAlert::STATUS_OPEN,
    ]);

    actingAs($user)
        ->get(route('inventory.reorder-alerts.api'))
        ->assertOk()
        ->assertJsonStructure([
            'alerts' => ['data', 'current_page', 'last_page', 'per_page', 'total'],
            'summary' => ['total_open', 'total_acknowledged', 'total_resolved'],
        ]);
});
