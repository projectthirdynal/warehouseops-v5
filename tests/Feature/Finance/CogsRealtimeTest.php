<?php

use App\Domain\Finance\Models\CogsDailySummary;
use App\Domain\Finance\Models\CogsEntry;
use App\Domain\Finance\Models\CogsVarianceAlert;
use App\Domain\Finance\Services\CogsDashboardService;
use App\Domain\Product\Models\Product;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
    $this->service = app(CogsDashboardService::class);
    $this->withoutMiddleware([VerifyCsrfToken::class]);
});

function cogsProduct(float $costPrice = 100, float $sellingPrice = 150): Product
{
    return Product::factory()->create([
        'cost_price' => $costPrice,
        'selling_price' => $sellingPrice,
    ]);
}

function cogsEntry(int $productId, float $qty, float $unitCost, ?string $date = null, ?int $orderId = null): CogsEntry
{
    return CogsEntry::create([
        'product_id' => $productId,
        'variant_id' => null,
        'waybill_id' => null,
        'order_id' => $orderId,
        'cost_lot_id' => null,
        'method' => 'FIFO',
        'quantity' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => round($qty * $unitCost, 4),
        'currency_code' => 'PHP',
        'exchange_rate' => 1.0,
        'recorded_at' => $date ?? now(),
    ]);
}

test('dashboard renders with real-time stats', function () {
    $product = cogsProduct();
    cogsEntry($product->id, 10, 50, now()->toDateString());

    $response = $this->actingAs($this->user)
        ->get('/finance/cost-of-goods/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('dashboard.today')
        ->has('dashboard.period')
        ->has('dashboard.trend')
        ->has('dashboard.top_products')
        ->has('dashboard.alerts')
    );
});

test('generate daily summary creates per-product aggregates', function () {
    $product1 = cogsProduct(100);
    $product2 = cogsProduct(200);
    $date = '2026-08-05';

    cogsEntry($product1->id, 10, 95, $date, 1001);
    cogsEntry($product1->id, 5, 100, $date, 1002);
    cogsEntry($product2->id, 3, 210, $date, 1003);

    $count = $this->service->generateDailySummary($date);

    expect($count)->toBe(2);

    $summary1 = CogsDailySummary::where('product_id', $product1->id)
        ->where('summary_date', $date)
        ->first();

    expect($summary1)->not->toBeNull()
        ->and((float) $summary1->total_quantity)->toBe(15.0)
        ->and((float) $summary1->total_cost)->toBe(1450.0) // (10*95) + (5*100)
        ->and((float) $summary1->avg_unit_cost)->toBe(96.6667)
        ->and((float) $summary1->standard_cost)->toBe(100.0)
        ->and($summary1->entries_count)->toBe(2)
        ->and($summary1->orders_count)->toBe(2);
});

test('generate daily summary creates variance alert when cost exceeds threshold', function () {
    $product = cogsProduct(100); // standard cost = 100
    $date = '2026-08-05';

    // Actual unit cost = 110, which is 10% above standard (threshold is 5%)
    cogsEntry($product->id, 10, 110, $date);

    $this->service->generateDailySummary($date);

    $alert = CogsVarianceAlert::where('product_id', $product->id)
        ->where('alert_date', $date)
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->severity)->toBe('MEDIUM')
        ->and($alert->alert_type)->toBe('COST_VARIANCE')
        ->and((float) $alert->actual_cost)->toBe(110.0)
        ->and((float) $alert->standard_cost)->toBe(100.0)
        ->and((float) $alert->variance_pct)->toBe(10.0)
        ->and($alert->resolved)->toBeFalse();
});

test('generate daily summary creates HIGH severity alert for 20%+ variance', function () {
    $product = cogsProduct(100);
    $date = '2026-08-05';

    // Actual unit cost = 125, which is 25% above standard
    cogsEntry($product->id, 10, 125, $date);

    $this->service->generateDailySummary($date);

    $alert = CogsVarianceAlert::where('product_id', $product->id)
        ->where('alert_date', $date)
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->severity)->toBe('HIGH');
});

test('no variance alert created when cost within threshold', function () {
    $product = cogsProduct(100);
    $date = '2026-08-05';

    // Actual unit cost = 102, which is 2% above standard (below 5% threshold)
    cogsEntry($product->id, 10, 102, $date);

    $this->service->generateDailySummary($date);

    $alert = CogsVarianceAlert::where('product_id', $product->id)
        ->where('alert_date', $date)
        ->first();

    expect($alert)->toBeNull();
});

test('get realtime stats returns correct values', function () {
    $product = cogsProduct();
    $today = now()->toDateString();

    cogsEntry($product->id, 10, 50, $today, 1001);
    cogsEntry($product->id, 5, 50, $today, 1002);

    $stats = $this->service->getRealtimeStats($today);

    expect($stats['total_cost'])->toBe(750.0)
        ->and($stats['total_quantity'])->toBe(15.0)
        ->and($stats['entries_count'])->toBe(2)
        ->and($stats['orders_count'])->toBe(2)
        ->and($stats['avg_unit_cost'])->toBe(50.0);
});

test('resolve alert marks as resolved with user and note', function () {
    $product = cogsProduct(100);
    $date = '2026-08-05';

    cogsEntry($product->id, 10, 120, $date);
    $this->service->generateDailySummary($date);

    $alert = CogsVarianceAlert::where('product_id', $product->id)->first();
    expect($alert)->not->toBeNull();

    $resolved = $this->service->resolveAlert($alert->id, $this->user->id, 'Investigated - supplier price increase confirmed.');

    expect($resolved->resolved)->toBeTrue()
        ->and($resolved->resolved_by)->toBe($this->user->id)
        ->and($resolved->resolution_note)->toBe('Investigated - supplier price increase confirmed.')
        ->and($resolved->resolved_at)->not->toBeNull();
});

test('get daily summary detail returns per-product breakdown', function () {
    $product1 = cogsProduct(100);
    $product2 = cogsProduct(200);
    $date = '2026-08-05';

    cogsEntry($product1->id, 10, 95, $date);
    cogsEntry($product2->id, 3, 210, $date);

    $this->service->generateDailySummary($date);

    $detail = $this->service->getDailySummaryDetail($date);

    expect($detail['date'])->toBe($date)
        ->and($detail['summaries'])->toHaveCount(2)
        ->and($detail['totals']['total_cost'])->toBe(1580.0) // 950 + 630
        ->and($detail['totals']['total_quantity'])->toBe(13.0)
        ->and($detail['totals']['products_count'])->toBe(2);
});

test('api dashboard endpoint returns json', function () {
    $product = cogsProduct();
    cogsEntry($product->id, 10, 50, now()->toDateString());

    $response = $this->actingAs($this->user)
        ->getJson('/finance/api/cogs/dashboard');

    $response->assertOk()
        ->assertJsonStructure([
            'today' => ['total_cost', 'total_quantity', 'avg_unit_cost', 'entries_count', 'orders_count', 'unsynced_count', 'unsynced_cost'],
            'period',
            'trend',
            'top_products',
            'alerts',
            'days',
        ]);
});

test('api daily summary endpoint returns json', function () {
    $product = cogsProduct(100);
    $date = '2026-08-05';

    cogsEntry($product->id, 10, 95, $date);
    $this->service->generateDailySummary($date);

    $response = $this->actingAs($this->user)
        ->getJson("/finance/api/cogs/daily-summary?date={$date}");

    $response->assertOk()
        ->assertJsonStructure([
            'date',
            'summaries',
            'totals',
        ]);
});

test('resolve alert endpoint works via patch', function () {
    $product = cogsProduct(100);
    $date = '2026-08-05';

    cogsEntry($product->id, 10, 120, $date);
    $this->service->generateDailySummary($date);

    $alert = CogsVarianceAlert::where('product_id', $product->id)->first();

    $response = $this->actingAs($this->user)
        ->patch("/finance/api/cogs/alerts/{$alert->id}/resolve", [
            'note' => 'Price increase verified with supplier.',
        ]);

    $response->assertRedirect();
    expect($alert->fresh()->resolved)->toBeTrue();
});

test('scheduled command generates summaries', function () {
    $product = cogsProduct(100);
    $date = now()->toDateString();

    cogsEntry($product->id, 10, 95, $date);

    $this->artisan('cogs:generate-daily-summary', ['--date' => $date])
        ->assertSuccessful()
        ->expectsOutputToContain('1 summaries generated');

    expect(CogsDailySummary::where('product_id', $product->id)->where('summary_date', $date)->exists())->toBeTrue();
});

test('regenerating daily summary updates existing records', function () {
    $product = cogsProduct(100);
    $date = '2026-08-05';

    cogsEntry($product->id, 5, 100, $date);
    $this->service->generateDailySummary($date);

    // Add more entries for same date
    cogsEntry($product->id, 10, 100, $date);
    $this->service->generateDailySummary($date);

    $summary = CogsDailySummary::where('product_id', $product->id)
        ->where('summary_date', $date)
        ->first();

    expect((float) $summary->total_quantity)->toBe(15.0)
        ->and($summary->entries_count)->toBe(2);
});
