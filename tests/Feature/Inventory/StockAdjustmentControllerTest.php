<?php

use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\Supply;
use Modules\Inventory\Models\SupplyStock;
use Modules\Inventory\Models\Warehouse;
use App\Models\User;

use function Pest\Laravel\actingAs;

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeAdjUser(): User
{
    return User::factory()->create(['role' => 'warehouse']);
}

function makeSupervisor(): User
{
    return User::factory()->create(['role' => 'supervisor']);
}

function makeAdjSupply(): Supply
{
    return Supply::factory()->create(['is_active' => true]);
}

function makeAdjWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view adjustments index', function () {
    $user = makeAdjUser();

    actingAs($user)
        ->get(route('inventory.adjustments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/StockAdjustments'));
});

// ─── Store ──────────────────────────────────────────────────────────────────

test('can submit a supply stock adjustment', function () {
    $user = makeAdjUser();
    $supply = makeAdjSupply();
    $warehouse = makeAdjWarehouse();

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 40,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);

    actingAs($user)
        ->post(route('inventory.adjustments.store'), [
            'supply_id' => $supply->id,
            'warehouse_id' => $warehouse->id,
            'reason_code' => 'CYCLE_COUNT',
            'reason_notes' => 'Monthly count',
            'quantity_after' => 35,
        ])
        ->assertRedirect();

    $adj = StockAdjustment::where('supply_id', $supply->id)->first();

    expect($adj)->not->toBeNull()
        ->and($adj->status)->toBe('PENDING')
        ->and($adj->quantity_before)->toBe(40)
        ->and($adj->quantity_after)->toBe(35)
        ->and($adj->variance)->toBe(-5);
});

test('adjustment store requires either product_id or supply_id', function () {
    $user = makeAdjUser();
    $warehouse = makeAdjWarehouse();

    actingAs($user)
        ->post(route('inventory.adjustments.store'), [
            'warehouse_id' => $warehouse->id,
            'reason_code' => 'CYCLE_COUNT',
            'quantity_after' => 10,
        ])
        ->assertSessionHasErrors();
});

// ─── Approve ────────────────────────────────────────────────────────────────

test('supervisor can approve a pending adjustment and stock is updated', function () {
    $supervisor = makeSupervisor();
    $supply = makeAdjSupply();
    $warehouse = makeAdjWarehouse();

    $stock = SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 50,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);

    $adj = StockAdjustment::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'reason_code' => 'DAMAGE',
        'quantity_before' => 50,
        'quantity_after' => 42,
        'variance' => -8,
        'status' => 'PENDING',
        'submitted_by' => $supervisor->id,
    ]);

    actingAs($supervisor)
        ->post(route('inventory.adjustments.approve', $adj->id))
        ->assertRedirect();

    expect($adj->fresh()->status)->toBe('APPROVED')
        ->and((int) $stock->fresh()->current_stock)->toBe(42);
});

test('cannot approve an already-processed adjustment', function () {
    $supervisor = makeSupervisor();
    $supply = makeAdjSupply();
    $warehouse = makeAdjWarehouse();

    $adj = StockAdjustment::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'reason_code' => 'DAMAGE',
        'quantity_before' => 10,
        'quantity_after' => 8,
        'variance' => -2,
        'status' => 'APPROVED',
        'submitted_by' => $supervisor->id,
    ]);

    actingAs($supervisor)
        ->post(route('inventory.adjustments.approve', $adj->id))
        ->assertRedirect()
        ->assertSessionHas('error');
});

// ─── Reject ─────────────────────────────────────────────────────────────────

test('supervisor can reject a pending adjustment', function () {
    $supervisor = makeSupervisor();
    $supply = makeAdjSupply();
    $warehouse = makeAdjWarehouse();

    $adj = StockAdjustment::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'reason_code' => 'CYCLE_COUNT',
        'quantity_before' => 20,
        'quantity_after' => 15,
        'variance' => -5,
        'status' => 'PENDING',
        'submitted_by' => $supervisor->id,
    ]);

    actingAs($supervisor)
        ->post(route('inventory.adjustments.reject', $adj->id), [
            'reason' => 'Count error',
        ])
        ->assertRedirect();

    expect($adj->fresh()->status)->toBe('REJECTED');
});

test('stock is NOT changed when adjustment is rejected', function () {
    $supervisor = makeSupervisor();
    $supply = makeAdjSupply();
    $warehouse = makeAdjWarehouse();

    $stock = SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 30,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);

    $adj = StockAdjustment::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $warehouse->id,
        'reason_code' => 'CYCLE_COUNT',
        'quantity_before' => 30,
        'quantity_after' => 25,
        'variance' => -5,
        'status' => 'PENDING',
        'submitted_by' => $supervisor->id,
    ]);

    actingAs($supervisor)
        ->post(route('inventory.adjustments.reject', $adj->id), ['reason' => 'Wrong count']);

    expect((int) $stock->fresh()->current_stock)->toBe(30);
});

// ─── Report + Export ────────────────────────────────────────────────────────

test('report page loads', function () {
    $user = makeSupervisor();

    actingAs($user)
        ->get(route('inventory.adjustments.report'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/AdjustmentReport'));
});

test('report download returns csv', function () {
    $user = makeSupervisor();

    actingAs($user)
        ->get(route('inventory.adjustments.report.download'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=utf-8');
});
