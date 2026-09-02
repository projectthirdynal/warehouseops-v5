<?php

use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\WarehouseMapService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;
use App\Models\User;

use function Pest\Laravel\actingAs;

function makeMapUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

function makeMapWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true, 'is_default' => true]);
}

function makeMapLocation(Warehouse $warehouse, array $overrides = []): WarehouseLocation
{
    return WarehouseLocation::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'code' => 'LOC-'.fake()->unique()->bothify('##'),
        'name' => 'Test Location',
        'type' => 'SHELF',
        'capacity' => 100,
        'is_active' => true,
        'row_index' => 0,
        'col_index' => 0,
    ], $overrides));
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view warehouse map index', function () {
    $user = makeMapUser();

    actingAs($user)
        ->get(route('inventory.warehouse-map.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/WarehouseMap'));
});

test('index returns overview with active warehouses', function () {
    Warehouse::query()->delete();
    $user = makeMapUser();
    $wh1 = makeMapWarehouse();
    $wh2 = Warehouse::factory()->create(['is_active' => false]);

    actingAs($user)
        ->get(route('inventory.warehouse-map.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('overview')
            ->where('overview.0.id', $wh1->id)
        );
});

// ─── API: Warehouse Map ─────────────────────────────────────────────────────

test('api returns warehouse map with grid', function () {
    $user = makeMapUser();
    $wh = makeMapWarehouse();
    makeMapLocation($wh, ['code' => 'A1', 'row_index' => 0, 'col_index' => 0]);
    makeMapLocation($wh, ['code' => 'A2', 'row_index' => 0, 'col_index' => 1]);
    makeMapLocation($wh, ['code' => 'B1', 'row_index' => 1, 'col_index' => 0]);

    actingAs($user)
        ->get(route('inventory.warehouse-map.warehouse', ['warehouseId' => $wh->id]))
        ->assertOk()
        ->assertJsonStructure([
            'warehouse' => ['id', 'name', 'code', 'address', 'is_active'],
            'grid',
            'grid_dimensions' => ['rows', 'cols'],
            'summary' => [
                'total_locations', 'empty', 'low', 'medium', 'high', 'full', 'inactive',
                'total_stock', 'total_reserved', 'total_available', 'total_capacity',
                'total_skus', 'overall_occupancy',
            ],
        ]);
});

test('api returns correct grid dimensions', function () {
    $user = makeMapUser();
    $wh = makeMapWarehouse();
    makeMapLocation($wh, ['code' => 'A1', 'row_index' => 0, 'col_index' => 0]);
    makeMapLocation($wh, ['code' => 'C3', 'row_index' => 2, 'col_index' => 2]);

    actingAs($user)
        ->get(route('inventory.warehouse-map.warehouse', ['warehouseId' => $wh->id]))
        ->assertOk()
        ->assertJsonPath('grid_dimensions.rows', 3)
        ->assertJsonPath('grid_dimensions.cols', 3);
});

test('api computes occupancy status correctly', function () {
    $user = makeMapUser();
    $wh = makeMapWarehouse();

    // Empty location (no stock)
    makeMapLocation($wh, ['code' => 'EMPTY', 'capacity' => 100, 'row_index' => 0, 'col_index' => 0]);

    // Full location (stock at 100% capacity)
    $fullLoc = makeMapLocation($wh, ['code' => 'FULL', 'capacity' => 100, 'row_index' => 0, 'col_index' => 1]);
    ProductStock::create([
        'product_id' => Product::factory()->create()->id,
        'variant_id' => null,
        'warehouse_id' => $wh->id,
        'location_id' => $fullLoc->id,
        'current_stock' => 95,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    // Inactive location
    makeMapLocation($wh, ['code' => 'INACT', 'is_active' => false, 'row_index' => 0, 'col_index' => 2]);

    actingAs($user)
        ->get(route('inventory.warehouse-map.warehouse', ['warehouseId' => $wh->id]))
        ->assertOk()
        ->assertJson(fn ($json) => $json
            ->where('summary.empty', 1)
            ->where('summary.full', 1)
            ->where('summary.inactive', 1)
            ->etc()
        );
});

// ─── API: Location Details ──────────────────────────────────────────────────

test('api returns location details with product stocks', function () {
    $user = makeMapUser();
    $wh = makeMapWarehouse();
    $loc = makeMapLocation($wh, ['code' => 'DET-1']);
    $product = Product::factory()->create(['sku' => 'PROD-MAP1']);
    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $wh->id,
        'location_id' => $loc->id,
        'current_stock' => 50,
        'reserved_stock' => 10,
        'reorder_point' => 5,
    ]);

    actingAs($user)
        ->get(route('inventory.warehouse-map.location', ['locationId' => $loc->id]))
        ->assertOk()
        ->assertJsonStructure([
            'location' => ['id', 'code', 'name', 'type', 'capacity', 'is_active', 'row_index', 'col_index', 'zone_color', 'warehouse'],
            'product_stocks',
            'supply_stocks',
        ])
        ->assertJsonPath('product_stocks.0.sku', 'PROD-MAP1')
        ->assertJsonPath('product_stocks.0.current_stock', 50);
});

test('api returns empty stocks for empty location', function () {
    $user = makeMapUser();
    $wh = makeMapWarehouse();
    $loc = makeMapLocation($wh, ['code' => 'EMPTY-DET']);

    actingAs($user)
        ->get(route('inventory.warehouse-map.location', ['locationId' => $loc->id]))
        ->assertOk()
        ->assertJsonPath('product_stocks', [])
        ->assertJsonPath('supply_stocks', []);
});

// ─── API: Update Coordinates ────────────────────────────────────────────────

test('api updates location coordinates', function () {
    $user = makeMapUser();
    $wh = makeMapWarehouse();
    $loc = makeMapLocation($wh, ['code' => 'MOVE-1', 'row_index' => 0, 'col_index' => 0]);

    actingAs($user)
        ->putJson(route('inventory.warehouse-map.coordinates', ['locationId' => $loc->id]), [
            'row_index' => 3,
            'col_index' => 5,
            'zone_color' => '#ff0000',
        ])
        ->assertOk()
        ->assertJsonPath('row_index', 3)
        ->assertJsonPath('col_index', 5)
        ->assertJsonPath('zone_color', '#ff0000');

    expect($loc->fresh()->row_index)->toBe(3)
        ->and($loc->fresh()->col_index)->toBe(5)
        ->and($loc->fresh()->zone_color)->toBe('#ff0000');
});

test('api validates coordinate input', function () {
    $user = makeMapUser();
    $wh = makeMapWarehouse();
    $loc = makeMapLocation($wh, ['code' => 'VAL-1']);

    actingAs($user)
        ->putJson(route('inventory.warehouse-map.coordinates', ['locationId' => $loc->id]), [
            'row_index' => 'not-a-number',
            'col_index' => -1,
        ])
        ->assertStatus(422);
});

// ─── Service: getWarehouseMap ───────────────────────────────────────────────

test('service returns correct summary', function () {
    $wh = makeMapWarehouse();
    makeMapLocation($wh, ['code' => 'S1', 'capacity' => 100, 'row_index' => 0, 'col_index' => 0]);
    makeMapLocation($wh, ['code' => 'S2', 'capacity' => 200, 'row_index' => 0, 'col_index' => 1]);

    $service = app(WarehouseMapService::class);
    $data = $service->getWarehouseMap($wh->id);

    expect($data['summary']['total_locations'])->toBe(2)
        ->and($data['summary']['empty'])->toBe(2)
        ->and($data['summary']['total_capacity'])->toBe(300);
});

// ─── Service: getAllWarehousesOverview ──────────────────────────────────────

test('service overview only includes active warehouses', function () {
    Warehouse::query()->delete();
    $wh1 = makeMapWarehouse();
    $wh2 = Warehouse::factory()->create(['is_active' => false]);

    $service = app(WarehouseMapService::class);
    $overview = $service->getAllWarehousesOverview();

    expect($overview)->toHaveCount(1)
        ->and($overview[0]['id'])->toBe($wh1->id);
});

// ─── Service: updateLocationCoordinates ─────────────────────────────────────

test('service updates coordinates without zone color', function () {
    $wh = makeMapWarehouse();
    $loc = makeMapLocation($wh, ['code' => 'UPD-1']);

    $service = app(WarehouseMapService::class);
    $updated = $service->updateLocationCoordinates($loc->id, 2, 3);

    expect($updated->row_index)->toBe(2)
        ->and($updated->col_index)->toBe(3)
        ->and($updated->zone_color)->toBeNull();
});

// ─── Access control ─────────────────────────────────────────────────────────

test('non-inventory role cannot access warehouse map', function () {
    $user = User::factory()->create(['role' => 'agent', 'is_active' => true]);

    actingAs($user)
        ->get(route('inventory.warehouse-map.index'))
        ->assertRedirect();
});
