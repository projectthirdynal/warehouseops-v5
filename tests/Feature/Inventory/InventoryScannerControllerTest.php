<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('scan endpoint requires authentication', function () {
    $response = $this->post('/inventory/scan', ['barcode' => 'TEST123']);
    $response->assertStatus(401);
});

test('scan endpoint requires authorized role', function () {
    $user = User::factory()->create(['role' => 'agent']);
    $response = $this->actingAs($user)->post('/inventory/scan', ['barcode' => 'TEST123']);
    $response->assertStatus(403);
});

test('scan endpoint finds product by barcode', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create(['barcode' => 'BARCODE123']);
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 100,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan', ['barcode' => 'BARCODE123']);
    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'found',
        'product' => [
            'id' => $product->id,
            'barcode' => 'BARCODE123',
        ],
    ]);
});

test('scan endpoint finds product by qr_code', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create(['qr_code' => 'QR123']);
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 50,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan', ['barcode' => 'QR123']);
    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'found',
        'product' => [
            'id' => $product->id,
            'qr_code' => 'QR123',
        ],
    ]);
});

test('scan endpoint finds product by sku', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create(['sku' => 'SKU-001']);
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 25,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan', ['barcode' => 'SKU-001']);
    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'found',
        'product' => [
            'id' => $product->id,
            'sku' => 'SKU-001',
        ],
    ]);
});

test('scan lookup is deterministic: barcode has priority over sku', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    
    // Create two products with same barcode/sku value
    $product1 = Product::factory()->create(['barcode' => 'DUPLICATE', 'sku' => 'SKU-A']);
    $product2 = Product::factory()->create(['barcode' => 'OTHER', 'sku' => 'DUPLICATE']);
    
    ProductStock::factory()->create([
        'product_id' => $product1->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 10,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan', ['barcode' => 'DUPLICATE']);
    $response->assertStatus(200);
    // Should match product1 by barcode, not product2 by sku
    $response->assertJson([
        'status' => 'found',
        'product' => [
            'id' => $product1->id,
            'barcode' => 'DUPLICATE',
        ],
    ]);
});

test('scan returns 404 for unknown barcode', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $response = $this->actingAs($user)->post('/inventory/scan', ['barcode' => 'UNKNOWN']);
    $response->assertStatus(404);
    $response->assertJson([
        'status' => 'not_found',
        'barcode' => 'UNKNOWN',
    ]);
});

test('quickAdjust creates pending adjustment', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 100,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan/adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => 95,
        'reason_code' => 'PHYSICAL_COUNT',
        'reason_notes' => 'Cycle count adjustment',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'submitted',
        'message' => 'Stock adjustment submitted for approval.',
    ]);

    $this->assertDatabaseHas('stock_adjustments', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_before' => 100,
        'quantity_after' => 95,
        'variance' => -5,
        'status' => 'PENDING',
        'submitted_by' => $user->id,
    ]);
});

test('quickAdjust validates quantity bounds', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->post('/inventory/scan/adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => -1, // Invalid: negative
        'reason_code' => 'TEST',
    ]);

    $response->assertStatus(422);

    $response = $this->actingAs($user)->post('/inventory/scan/adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => 1000000, // Invalid: exceeds max
        'reason_code' => 'TEST',
    ]);

    $response->assertStatus(422);
});

test('autoAdjust is blocked for warehouse role', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->post('/inventory/scan/auto-adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => 100,
        'reason_code' => 'TEST',
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'status' => 'error',
        'message' => 'Unauthorized.',
    ]);
});

test('autoAdjust is blocked for accounting role', function () {
    $user = User::factory()->create(['role' => 'accounting']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->post('/inventory/scan/auto-adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => 100,
        'reason_code' => 'TEST',
    ]);

    $response->assertStatus(403);
});

test('autoAdjust is allowed for supervisor', function () {
    $user = User::factory()->create(['role' => 'supervisor']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 50,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan/auto-adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => 75,
        'reason_code' => 'PHYSICAL_COUNT',
        'reason_notes' => 'Supervisor adjustment',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'approved',
        'message' => 'Stock adjusted successfully.',
    ]);

    // Verify stock was actually updated
    $stock = ProductStock::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->first();
    expect($stock->current_stock)->toBe(75);

    // Verify inventory movement was created
    $this->assertDatabaseHas('inventory_movements', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'type' => 'ADJUSTMENT',
        'quantity' => 25,
        'performed_by' => $user->id,
    ]);
});

test('autoAdjust is allowed for admin', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 0,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan/auto-adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => 10,
        'reason_code' => 'STOCK_IN',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['status' => 'approved']);
});

test('autoAdjust is allowed for superadmin', function () {
    $user = User::factory()->create(['role' => 'superadmin']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 200,
    ]);

    $response = $this->actingAs($user)->post('/inventory/scan/auto-adjust', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_after' => 180,
        'reason_code' => 'DAMAGE',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['status' => 'approved']);
});

test('quickAdjust uses transaction to prevent race condition', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $warehouse = Warehouse::factory()->create(['is_default' => true]);
    $product = Product::factory()->create();
    ProductStock::factory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'current_stock' => 100,
    ]);

    // Simulate concurrent adjustments
    $responses = [];
    for ($i = 0; $i < 5; $i++) {
        $responses[] = $this->actingAs($user)->post('/inventory/scan/adjust', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_after' => 95 - $i,
            'reason_code' => 'TEST',
        ]);
    }

    // All should succeed (created pending adjustments)
    foreach ($responses as $response) {
        $response->assertStatus(200);
    }

    // All adjustments should be recorded with correct quantity_before
    $adjustments = DB::table('stock_adjustments')
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->get();

    expect($adjustments)->toHaveCount(5);
    foreach ($adjustments as $adj) {
        expect($adj->quantity_before)->toBe(100);
    }
});
