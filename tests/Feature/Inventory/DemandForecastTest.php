<?php

use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\DemandForecastService;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;
use Modules\Shop\Models\ShopOrderItem;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

function makeForecastUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

function makeForecastProduct(array $overrides = []): Product
{
    return Product::factory()->create($overrides);
}

function makeDeliveredSale(Product $product, Carbon $date, int $quantity): ShopOrderItem
{
    $order = Order::create([
        'order_number' => 'ORD-'.fake()->unique()->numerify('#######'),
        'product_id' => $product->id,
        'status' => OrderStatus::DELIVERED->value,
        'quantity' => $quantity,
        'unit_price' => 100,
        'total_amount' => 100 * $quantity,
        'receiver_name' => 'Test Receiver',
        'receiver_phone' => '09171234567',
        'receiver_address' => 'Test Address',
    ]);
    $order->created_at = $date;
    $order->save();

    return ShopOrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => 100,
        'line_total' => 100 * $quantity,
    ]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view demand forecasting index', function () {
    $user = makeForecastUser();

    actingAs($user)
        ->get(route('inventory.demand-forecast.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/DemandForecasting'));
});

// ─── API: summary list ───────────────────────────────────────────────────────

test('api summary returns products with sales history', function () {
    $user = makeForecastUser();
    $product = makeForecastProduct(['sku' => 'FCST-1']);

    for ($i = 1; $i <= 10; $i++) {
        makeDeliveredSale($product, Carbon::now()->subDays($i), 5);
    }

    actingAs($user)
        ->getJson(route('inventory.demand-forecast.api'))
        ->assertOk()
        ->assertJsonStructure([
            'summary' => [
                '*' => [
                    'product_id', 'sku', 'name', 'total_historical_qty', 'avg_daily_qty',
                    'growth_rate', 'trend_direction', 'forecast_30d_qty', 'current_stock',
                    'available_stock', 'reorder_point', 'suggested_reorder_qty', 'needs_reorder',
                ],
            ],
        ])
        ->assertJsonPath('summary.0.sku', 'FCST-1')
        ->assertJsonPath('summary.0.total_historical_qty', 50);
});

test('api summary excludes non-delivered orders', function () {
    $user = makeForecastUser();
    $product = makeForecastProduct(['sku' => 'FCST-2']);

    $order = Order::create([
        'order_number' => 'ORD-'.fake()->unique()->numerify('#######'),
        'product_id' => $product->id,
        'status' => OrderStatus::CANCELLED->value,
        'quantity' => 5,
        'unit_price' => 100,
        'total_amount' => 500,
        'receiver_name' => 'Test Receiver',
        'receiver_phone' => '09171234567',
        'receiver_address' => 'Test Address',
    ]);
    ShopOrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 5,
        'unit_price' => 100,
        'line_total' => 500,
    ]);

    actingAs($user)
        ->getJson(route('inventory.demand-forecast.api'))
        ->assertOk()
        ->assertJsonMissing(['sku' => 'FCST-2']);
});

test('api summary flags products needing reorder', function () {
    $user = makeForecastUser();
    $product = makeForecastProduct(['sku' => 'FCST-3']);

    for ($i = 1; $i <= 30; $i++) {
        makeDeliveredSale($product, Carbon::now()->subDays($i), 10);
    }

    ProductStock::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => Warehouse::factory()->create()->id,
        'current_stock' => 5,
        'reserved_stock' => 0,
        'reorder_point' => 20,
    ]);

    actingAs($user)
        ->getJson(route('inventory.demand-forecast.api'))
        ->assertOk()
        ->assertJsonPath('summary.0.needs_reorder', true);
});

// ─── API: product detail ─────────────────────────────────────────────────────

test('api product detail returns forecast structure', function () {
    $user = makeForecastUser();
    $product = makeForecastProduct(['sku' => 'FCST-4']);

    for ($i = 1; $i <= 20; $i++) {
        makeDeliveredSale($product, Carbon::now()->subDays($i), 3);
    }

    actingAs($user)
        ->getJson(route('inventory.demand-forecast.product', ['productId' => $product->id]))
        ->assertOk()
        ->assertJsonStructure([
            'product' => ['id', 'sku', 'name'],
            'history',
            'summary' => [
                'total_historical_qty', 'avg_daily_qty', 'growth_rate', 'trend_direction',
                'data_sufficient', 'history_days', 'sale_day_count',
            ],
            'forecast',
            'total_forecast_qty',
            'stock' => [
                'current_stock', 'reserved_stock', 'available_stock', 'reorder_point',
                'suggested_reorder_qty', 'needs_reorder',
            ],
        ])
        ->assertJsonPath('product.sku', 'FCST-4');
});

test('api product detail flags insufficient data for low sales history', function () {
    $user = makeForecastUser();
    $product = makeForecastProduct(['sku' => 'FCST-5']);

    makeDeliveredSale($product, Carbon::now()->subDays(1), 2);

    actingAs($user)
        ->getJson(route('inventory.demand-forecast.product', ['productId' => $product->id]))
        ->assertOk()
        ->assertJsonPath('summary.data_sufficient', false);
});

test('api product detail returns 404 for non-existent product', function () {
    $user = makeForecastUser();

    actingAs($user)
        ->getJson(route('inventory.demand-forecast.product', ['productId' => 999999]))
        ->assertNotFound();
});

// ─── Service ─────────────────────────────────────────────────────────────────

test('service computes increasing trend correctly', function () {
    $product = makeForecastProduct(['sku' => 'FCST-6']);

    // Low sales in the first half of the history window, much higher sales
    // in the second half -> increasing trend across the full 90-day window.
    for ($i = 90; $i >= 1; $i--) {
        makeDeliveredSale($product, Carbon::now()->subDays($i), $i > 45 ? 1 : 50);
    }

    $service = app(DemandForecastService::class);
    $detail = $service->getProductForecastDetail($product->id, 30);

    expect($detail['summary']['trend_direction'])->toBe('increasing');
});

test('service returns zero forecast for product with no sales', function () {
    $product = makeForecastProduct(['sku' => 'FCST-7']);

    $service = app(DemandForecastService::class);
    $detail = $service->getProductForecastDetail($product->id, 30);

    expect($detail['summary']['total_historical_qty'])->toBe(0)
        ->and($detail['summary']['data_sufficient'])->toBeFalse();
});

// ─── Access control ─────────────────────────────────────────────────────────

test('non-inventory role cannot access demand forecasting', function () {
    $user = User::factory()->create(['role' => 'agent', 'is_active' => true]);

    actingAs($user)
        ->get(route('inventory.demand-forecast.index'))
        ->assertRedirect();
});
