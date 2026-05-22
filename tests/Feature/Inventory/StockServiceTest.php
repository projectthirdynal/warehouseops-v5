<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_active_reservations_for_a_reference_without_changing_physical_stock(): void
    {
        $product = $this->product();
        $warehouse = $this->warehouse();
        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'variant_id' => null,
            'warehouse_id' => $warehouse->id,
            'current_stock' => 10,
            'reserved_stock' => 0,
            'reorder_point' => 2,
        ]);

        $reservation = app(StockService::class)->reserve(
            productId: $product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            quantity: 4,
            referenceType: 'order',
            referenceId: 101,
            expiresAt: now()->addDay(),
        );

        $this->assertSame(4, $stock->refresh()->reserved_stock);

        $released = app(StockService::class)->releaseForReference('order', 101, 'order_cancelled');

        $this->assertSame(1, $released);
        $this->assertSame(10, $stock->refresh()->current_stock);
        $this->assertSame(0, $stock->reserved_stock);
        $this->assertSame('RELEASED', $reservation->refresh()->status);
        $this->assertSame('order_cancelled', $reservation->released_reason);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'RELEASE',
            'quantity' => 4,
            'reference_type' => 'order',
            'reference_id' => 101,
        ]);
    }

    public function test_it_consumes_active_reservations_for_a_reference_on_fulfillment(): void
    {
        $product = $this->product();
        $warehouse = $this->warehouse();
        $stock = ProductStock::query()->create([
            'product_id' => $product->id,
            'variant_id' => null,
            'warehouse_id' => $warehouse->id,
            'current_stock' => 10,
            'reserved_stock' => 0,
            'reorder_point' => 2,
        ]);

        $reservation = app(StockService::class)->reserve(
            productId: $product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            quantity: 3,
            referenceType: 'order',
            referenceId: 202,
            expiresAt: now()->addDay(),
            reservedBy: null,
        );

        $consumed = app(StockService::class)->consumeForReference('order', 202);

        $this->assertSame(1, $consumed);
        $this->assertSame(7, $stock->refresh()->current_stock);
        $this->assertSame(0, $stock->reserved_stock);
        $this->assertSame('CONSUMED', $reservation->refresh()->status);
        $this->assertSame('consumed', $reservation->released_reason);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'STOCK_OUT',
            'quantity' => -3,
            'reference_type' => 'order',
            'reference_id' => 202,
        ]);
    }

    public function test_reserve_rejects_requests_that_exceed_available_stock(): void
    {
        $product = $this->product();
        $warehouse = $this->warehouse();
        ProductStock::query()->create([
            'product_id' => $product->id,
            'variant_id' => null,
            'warehouse_id' => $warehouse->id,
            'current_stock' => 5,
            'reserved_stock' => 4,
            'reorder_point' => 2,
        ]);

        $this->expectException(InsufficientStockException::class);

        app(StockService::class)->reserve(
            productId: $product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            quantity: 2,
            referenceType: 'order',
            referenceId: 303,
            expiresAt: now()->addDay(),
        );
    }

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'TEST-SKU-' . uniqid(),
            'name' => 'Test Product',
            'selling_price' => 199,
            'cost_price' => 100,
            'weight_grams' => 100,
            'is_active' => true,
            'requires_qa' => false,
        ]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN-' . uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}
