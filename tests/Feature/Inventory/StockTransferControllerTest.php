<?php

use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Models\Supply;
use Modules\Inventory\Models\SupplyStock;
use Modules\Inventory\Models\Warehouse;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;
use App\Models\User;

use function Pest\Laravel\actingAs;

function makeTransferUser(): User
{
    return User::factory()->create(['role' => 'warehouse']);
}

function makeTransferApprover(): User
{
    return User::factory()->create(['role' => 'supervisor']);
}

function makeSourceWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

function makeDestinationWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view transfers index', function () {
    $user = makeTransferUser();

    actingAs($user)
        ->get(route('inventory.transfers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/StockTransfers'));
});

// ─── Store ──────────────────────────────────────────────────────────────────

test('warehouse user can submit a product transfer request', function () {
    $user = makeTransferUser();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $source->id,
        'current_stock' => 100,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);

    actingAs($user)
        ->post(route('inventory.transfers.store'), [
            'stockable_type' => 'product',
            'stockable_id' => $product->id,
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $dest->id,
            'quantity' => 30,
            'reason_notes' => 'Restock destination',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $transfer = StockTransfer::where('stockable_id', $product->id)->first();

    expect($transfer)->not->toBeNull()
        ->and($transfer->status)->toBe(StockTransfer::STATUS_PENDING)
        ->and($transfer->quantity)->toBe(30)
        ->and((int) $transfer->from_warehouse_id)->toBe($source->id)
        ->and((int) $transfer->to_warehouse_id)->toBe($dest->id);
});

test('warehouse user can submit a supply transfer request', function () {
    $user = makeTransferUser();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $source->id,
        'current_stock' => 50,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);

    actingAs($user)
        ->post(route('inventory.transfers.store'), [
            'stockable_type' => 'supply',
            'stockable_id' => $supply->id,
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $dest->id,
            'quantity' => 20,
        ])
        ->assertRedirect();

    expect(StockTransfer::where('stockable_id', $supply->id)->exists())->toBeTrue();
});

test('transfer request validates source and destination are different', function () {
    $user = makeTransferUser();
    $source = makeSourceWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    actingAs($user)
        ->post(route('inventory.transfers.store'), [
            'stockable_type' => 'supply',
            'stockable_id' => $supply->id,
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $source->id,
            'quantity' => 10,
        ])
        ->assertSessionHasErrors('to_warehouse_id');
});

test('transfer request validates sufficient source stock', function () {
    $user = makeTransferUser();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $source->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 1,
    ]);

    actingAs($user)
        ->post(route('inventory.transfers.store'), [
            'stockable_type' => 'supply',
            'stockable_id' => $supply->id,
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $dest->id,
            'quantity' => 10,
        ])
        ->assertSessionHasErrors('quantity');
});

// ─── Approve ────────────────────────────────────────────────────────────────

test('supervisor can approve a pending product transfer and stock moves', function () {
    $approver = makeTransferApprover();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $product = Product::factory()->create(['is_active' => true]);

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $source->id,
        'current_stock' => 80,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);
    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $dest->id,
        'current_stock' => 10,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);

    $transfer = StockTransfer::create([
        'stockable_type' => 'App\\Domain\\Product\\Models\\Product',
        'stockable_id' => $product->id,
        'from_warehouse_id' => $source->id,
        'to_warehouse_id' => $dest->id,
        'quantity' => 25,
        'status' => StockTransfer::STATUS_PENDING,
        'requested_by' => $approver->id,
    ]);

    actingAs($approver)
        ->post(route('inventory.transfers.approve', $transfer->id))
        ->assertRedirect();

    $transfer->refresh();

    expect($transfer->status)->toBe(StockTransfer::STATUS_COMPLETED)
        ->and($transfer->approved_by)->not->toBeNull()
        ->and((int) ProductStock::where('warehouse_id', $source->id)->value('current_stock'))->toBe(55)
        ->and((int) ProductStock::where('warehouse_id', $dest->id)->value('current_stock'))->toBe(35);
});

test('supervisor can approve a pending supply transfer and stock moves', function () {
    $approver = makeTransferApprover();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $source->id,
        'current_stock' => 60,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);
    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $dest->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);

    $transfer = StockTransfer::create([
        'stockable_type' => 'App\\Domain\\Inventory\\Models\\Supply',
        'stockable_id' => $supply->id,
        'from_warehouse_id' => $source->id,
        'to_warehouse_id' => $dest->id,
        'quantity' => 15,
        'status' => StockTransfer::STATUS_PENDING,
        'requested_by' => $approver->id,
    ]);

    actingAs($approver)
        ->post(route('inventory.transfers.approve', $transfer->id))
        ->assertRedirect();

    $transfer->refresh();

    expect($transfer->status)->toBe(StockTransfer::STATUS_COMPLETED)
        ->and((int) SupplyStock::where('warehouse_id', $source->id)->value('current_stock'))->toBe(45)
        ->and((int) SupplyStock::where('warehouse_id', $dest->id)->value('current_stock'))->toBe(20);
});

test('approval fails when source stock is insufficient', function () {
    $approver = makeTransferApprover();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    SupplyStock::create([
        'supply_id' => $supply->id,
        'warehouse_id' => $source->id,
        'current_stock' => 8,
        'reserved_stock' => 0,
        'reorder_point' => 5,
    ]);

    $transfer = StockTransfer::create([
        'stockable_type' => 'App\\Domain\\Inventory\\Models\\Supply',
        'stockable_id' => $supply->id,
        'from_warehouse_id' => $source->id,
        'to_warehouse_id' => $dest->id,
        'quantity' => 10,
        'status' => StockTransfer::STATUS_PENDING,
        'requested_by' => $approver->id,
    ]);

    actingAs($approver)
        ->from(route('inventory.transfers.index'))
        ->post(route('inventory.transfers.approve', $transfer->id))
        ->assertRedirect(route('inventory.transfers.index'))
        ->assertSessionHas('error');

    $transfer->refresh();
    expect($transfer->status)->toBe(StockTransfer::STATUS_PENDING);
});

// ─── Reject ─────────────────────────────────────────────────────────────────

test('supervisor can reject a pending transfer', function () {
    $approver = makeTransferApprover();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    $transfer = StockTransfer::create([
        'stockable_type' => 'App\\Domain\\Inventory\\Models\\Supply',
        'stockable_id' => $supply->id,
        'from_warehouse_id' => $source->id,
        'to_warehouse_id' => $dest->id,
        'quantity' => 10,
        'status' => StockTransfer::STATUS_PENDING,
        'requested_by' => $approver->id,
    ]);

    actingAs($approver)
        ->post(route('inventory.transfers.reject', $transfer->id), ['reason' => 'No longer needed'])
        ->assertRedirect();

    $transfer->refresh();

    expect($transfer->status)->toBe(StockTransfer::STATUS_REJECTED)
        ->and($transfer->reason_notes)->toContain('No longer needed');
});

// ─── Cancel ─────────────────────────────────────────────────────────────────

test('warehouse user can cancel their pending transfer', function () {
    $user = makeTransferUser();
    $source = makeSourceWarehouse();
    $dest = makeDestinationWarehouse();
    $supply = Supply::factory()->create(['is_active' => true]);

    $transfer = StockTransfer::create([
        'stockable_type' => 'App\\Domain\\Inventory\\Models\\Supply',
        'stockable_id' => $supply->id,
        'from_warehouse_id' => $source->id,
        'to_warehouse_id' => $dest->id,
        'quantity' => 10,
        'status' => StockTransfer::STATUS_PENDING,
        'requested_by' => $user->id,
    ]);

    actingAs($user)
        ->post(route('inventory.transfers.cancel', $transfer->id))
        ->assertRedirect();

    $transfer->refresh();

    expect($transfer->status)->toBe(StockTransfer::STATUS_CANCELLED);
});
