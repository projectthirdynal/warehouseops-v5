<?php

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\delete;

// ─── Helpers ───────────────────────────────────────────────────────────────

function makeWarehouseUser(): User
{
    return User::factory()->create(['role' => 'warehouse']);
}

function makeAdminUser(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function makeSupply(array $attrs = []): Supply
{
    return Supply::factory()->create($attrs);
}

function makeWarehouse(array $attrs = []): Warehouse
{
    return Warehouse::factory()->create(array_merge(['is_active' => true], $attrs));
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view supplies index', function () {
    $user = makeWarehouseUser();
    makeSupply(['name' => 'Test Material', 'is_active' => true]);

    actingAs($user)
        ->get(route('inventory.supplies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/Supplies/Index'));
});

test('guest is redirected from supplies index', function () {
    get(route('inventory.supplies.index'))->assertRedirect(route('login'));
});

// ─── Search ─────────────────────────────────────────────────────────────────

test('search returns matching supplies case-insensitively', function () {
    $user = makeWarehouseUser();
    makeSupply(['sku' => 'CLEAN-001', 'name' => 'Cleaning Agent', 'is_active' => true]);
    makeSupply(['sku' => 'PKG-001',   'name' => 'Packaging Tape', 'is_active' => true]);

    $response = actingAs($user)
        ->getJson(route('inventory.supplies.search', ['q' => 'clean']))
        ->assertOk();

    $data = $response->json();
    expect($data)->toHaveCount(1)
        ->and($data[0]['sku'])->toBe('CLEAN-001');
});

test('search returns empty for query shorter than 2 chars', function () {
    $user = makeWarehouseUser();

    actingAs($user)
        ->getJson(route('inventory.supplies.search', ['q' => 'c']))
        ->assertOk()
        ->assertExactJson([]);
});

test('search ignores soft-deleted supplies', function () {
    $user    = makeWarehouseUser();
    $deleted = makeSupply(['name' => 'Deleted Supply', 'is_active' => true]);
    $deleted->deleteWithReason('test');

    $response = actingAs($user)
        ->getJson(route('inventory.supplies.search', ['q' => 'Deleted']))
        ->assertOk();

    expect($response->json())->toHaveCount(0);
});

// ─── Store ──────────────────────────────────────────────────────────────────

test('admin can create a supply', function () {
    $user = makeAdminUser();
    makeWarehouse(['is_default' => true]);

    actingAs($user)
        ->post(route('inventory.supplies.store'), [
            'sku'           => 'NEW-001',
            'name'          => 'New Material',
            'cost_price'    => 25.50,
            'section'       => 'STOCK',
            'reorder_point' => 5,
            'is_active'     => true,
        ])
        ->assertRedirect();

    expect(Supply::where('sku', 'NEW-001')->exists())->toBeTrue();
});

test('store creates initial stock movement when initial_stock provided', function () {
    $user      = makeAdminUser();
    $warehouse = makeWarehouse(['is_default' => true]);

    actingAs($user)
        ->post(route('inventory.supplies.store'), [
            'sku'           => 'INIT-001',
            'name'          => 'With Initial Stock',
            'cost_price'    => 10,
            'reorder_point' => 5,
            'is_active'     => true,
            'initial_stock' => 50,
            'warehouse_id'  => $warehouse->id,
        ]);

    $supply = Supply::where('sku', 'INIT-001')->firstOrFail();
    $stock  = SupplyStock::where('supply_id', $supply->id)->first();

    expect($stock)->not->toBeNull()
        ->and((int) $stock->current_stock)->toBe(50);

    expect(\Illuminate\Support\Facades\DB::table('supply_movements')
        ->where('supply_id', $supply->id)
        ->where('type', 'STOCK_IN')
        ->exists()
    )->toBeTrue();
});

test('sku must be unique on store', function () {
    $user = makeAdminUser();
    makeSupply(['sku' => 'DUPE-001']);

    actingAs($user)
        ->post(route('inventory.supplies.store'), [
            'sku'        => 'DUPE-001',
            'name'       => 'Duplicate SKU',
            'cost_price' => 5,
        ])
        ->assertSessionHasErrors('sku');
});

// ─── Update ─────────────────────────────────────────────────────────────────

test('admin can update a supply', function () {
    $user   = makeAdminUser();
    $supply = makeSupply(['name' => 'Old Name']);

    actingAs($user)
        ->put(route('inventory.supplies.update', $supply), [
            'sku'        => $supply->sku,
            'name'       => 'Updated Name',
            'cost_price' => $supply->cost_price,
            'is_active'  => true,
        ])
        ->assertRedirect();

    expect($supply->fresh()->name)->toBe('Updated Name');
});

// ─── Destroy ────────────────────────────────────────────────────────────────

test('admin can soft-delete a supply with reason', function () {
    $user   = makeAdminUser();
    $supply = makeSupply();

    actingAs($user)
        ->delete(route('inventory.supplies.destroy', $supply), [
            'delete_reason' => 'Discontinued product',
        ])
        ->assertRedirect();

    expect(Supply::withTrashed()->find($supply->id)->trashed())->toBeTrue();
});

test('delete requires a reason', function () {
    $user   = makeAdminUser();
    $supply = makeSupply();

    actingAs($user)
        ->delete(route('inventory.supplies.destroy', $supply), [])
        ->assertSessionHasErrors('delete_reason');
});

// ─── adjustStock ────────────────────────────────────────────────────────────

test('stock_in increases current_stock', function () {
    $user      = makeAdminUser();
    $warehouse = makeWarehouse(['is_default' => true]);
    $supply    = makeSupply();

    SupplyStock::create([
        'supply_id'     => $supply->id,
        'warehouse_id'  => $warehouse->id,
        'current_stock' => 10,
        'reserved_stock'=> 0,
        'reorder_point' => 5,
    ]);

    actingAs($user)
        ->post(route('inventory.supplies.stock.adjust', $supply), [
            'type'         => 'stock_in',
            'quantity'     => 20,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertRedirect();

    expect((int) SupplyStock::where('supply_id', $supply->id)->value('current_stock'))->toBe(30);
});

test('stock_out decreases current_stock', function () {
    $user      = makeAdminUser();
    $warehouse = makeWarehouse(['is_default' => true]);
    $supply    = makeSupply();

    SupplyStock::create([
        'supply_id'     => $supply->id,
        'warehouse_id'  => $warehouse->id,
        'current_stock' => 30,
        'reserved_stock'=> 0,
        'reorder_point' => 5,
    ]);

    actingAs($user)
        ->post(route('inventory.supplies.stock.adjust', $supply), [
            'type'         => 'stock_out',
            'quantity'     => 10,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertRedirect();

    expect((int) SupplyStock::where('supply_id', $supply->id)->value('current_stock'))->toBe(20);
});

test('stock_out fails when quantity exceeds available', function () {
    $user      = makeAdminUser();
    $warehouse = makeWarehouse(['is_default' => true]);
    $supply    = makeSupply();

    SupplyStock::create([
        'supply_id'     => $supply->id,
        'warehouse_id'  => $warehouse->id,
        'current_stock' => 5,
        'reserved_stock'=> 0,
        'reorder_point' => 2,
    ]);

    actingAs($user)
        ->post(route('inventory.supplies.stock.adjust', $supply), [
            'type'         => 'stock_out',
            'quantity'     => 10,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertStatus(422);
});

test('stock_in with quantity 0 returns validation error', function () {
    $user      = makeAdminUser();
    $warehouse = makeWarehouse(['is_default' => true]);
    $supply    = makeSupply();

    actingAs($user)
        ->post(route('inventory.supplies.stock.adjust', $supply), [
            'type'         => 'stock_in',
            'quantity'     => 0,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertSessionHasErrors('quantity');
});

test('adjustment sets stock to exact quantity', function () {
    $user      = makeAdminUser();
    $warehouse = makeWarehouse(['is_default' => true]);
    $supply    = makeSupply();

    SupplyStock::create([
        'supply_id'     => $supply->id,
        'warehouse_id'  => $warehouse->id,
        'current_stock' => 100,
        'reserved_stock'=> 0,
        'reorder_point' => 5,
    ]);

    actingAs($user)
        ->post(route('inventory.supplies.stock.adjust', $supply), [
            'type'         => 'adjustment',
            'quantity'     => 45,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertRedirect();

    expect((int) SupplyStock::where('supply_id', $supply->id)->value('current_stock'))->toBe(45);
});

// ─── Export ─────────────────────────────────────────────────────────────────

test('export returns csv download', function () {
    $user = makeAdminUser();
    makeSupply(['name' => 'Export Test Material']);

    actingAs($user)
        ->get(route('inventory.supplies.export'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=utf-8');
});
