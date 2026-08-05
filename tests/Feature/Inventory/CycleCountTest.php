<?php

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAuditItem;
use App\Domain\Inventory\Models\StockAuditSession;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\CycleCountService;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Models\SiteSetting;
use App\Models\User;
use function Pest\Laravel\actingAs;

function ccUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

function ccWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true]);
}

function ccProductWithStock(Warehouse $wh, int $stock = 100): Product
{
    $p = Product::factory()->create(['is_active' => true, 'cost_price' => 50]);
    ProductStock::create([
        'product_id' => $p->id,
        'variant_id' => null,
        'warehouse_id' => $wh->id,
        'current_stock' => $stock,
        'reserved_stock' => 0,
        'reorder_point' => 10,
    ]);
    return $p;
}

test('index renders cycle counts page', function () {
    actingAs(ccUser())
        ->get(route('inventory.cycle-counts.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Inventory/CycleCounts'));
});

test('store creates a session with items', function () {
    $wh = ccWarehouse();
    ccProductWithStock($wh, 50);

    actingAs(ccUser())
        ->post(route('inventory.cycle-counts.store'), [
            'warehouse_id' => $wh->id, 'sample_size' => 5,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $s = StockAuditSession::where('warehouse_id', $wh->id)->first();
    expect($s)->not->toBeNull()
        ->and($s->status)->toBe(StockAuditSession::STATUS_OPEN)
        ->and($s->items()->count())->toBeLessThanOrEqual(5);
});

test('show renders detail page', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh);
    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);

    actingAs($u)
        ->get(route('inventory.cycle-counts.show', $s->id))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Inventory/CycleCountDetail'));
});

test('record count via route updates item', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh, 100);
    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);
    $item = $s->items()->first();

    actingAs($u)
        ->post(route('inventory.cycle-counts.items.count', $item->id), ['counted_qty' => 95])
        ->assertRedirect();

    $item->refresh();
    expect($item->status)->toBe(StockAuditItem::STATUS_COUNTED)
        ->and($item->variance)->toBe(-5);
});

test('skip item sets status skipped', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh);
    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);
    $item = $s->items()->first();

    actingAs($u)
        ->post(route('inventory.cycle-counts.items.skip', $item->id))
        ->assertRedirect();

    expect($item->fresh()->status)->toBe(StockAuditItem::STATUS_SKIPPED);
});

test('finalize creates adjustments for variances', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh, 100);
    SiteSetting::set('cycle_count_auto_create_adjustments', 'true');

    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);
    $item = $s->items()->first();
    $svc->recordCount($item->id, 90, $u->id);

    actingAs($u)
        ->post(route('inventory.cycle-counts.finalize', $s->id))
        ->assertRedirect();

    $item->refresh();
    expect($s->fresh()->status)->toBe(StockAuditSession::STATUS_FINALIZED)
        ->and($item->adjustment_id)->not->toBeNull();

    $adj = StockAdjustment::find($item->adjustment_id);
    expect($adj->reason_code)->toBe('CYCLE_COUNT')
        ->and($adj->status)->toBe('PENDING')
        ->and((int) $adj->variance)->toBe(-10);
});

test('finalize without auto-create skips adjustments', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh, 100);
    SiteSetting::set('cycle_count_auto_create_adjustments', 'false');

    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);
    $item = $s->items()->first();
    $svc->recordCount($item->id, 90, $u->id);
    $svc->finalizeSession($s->id, $u->id);

    expect($item->fresh()->adjustment_id)->toBeNull();
});

test('cannot finalize already finalized session', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh);
    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);
    $svc->finalizeSession($s->id, $u->id);

    expect(fn () => $svc->finalizeSession($s->id, $u->id))
        ->toThrow(RuntimeException::class);
});

test('cancel session sets cancelled status', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh);
    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);

    actingAs($u)
        ->post(route('inventory.cycle-counts.cancel', $s->id))
        ->assertRedirect();

    expect($s->fresh()->status)->toBe(StockAuditSession::STATUS_CANCELLED);
});

test('report renders with variance data', function () {
    $u = ccUser();
    $wh = ccWarehouse();
    ccProductWithStock($wh, 100);
    $svc = app(CycleCountService::class);
    $s = $svc->createSession($wh->id, 1, $u->id);
    $item = $s->items()->first();
    $svc->recordCount($item->id, 95, $u->id);
    $svc->finalizeSession($s->id, $u->id);

    actingAs($u)
        ->get(route('inventory.cycle-counts.report'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Inventory/CycleCountReport'));
});

test('scheduled generation respects auto_generate setting', function () {
    $wh = ccWarehouse();
    ccProductWithStock($wh);
    SiteSetting::set('cycle_count_auto_generate_enabled', 'false');

    $svc = app(CycleCountService::class);
    $result = $svc->generateScheduled();

    expect($result['generated'])->toBe(0);
});

test('scheduled generation creates session when enabled', function () {
    $wh = ccWarehouse();
    ccProductWithStock($wh);
    SiteSetting::set('cycle_count_auto_generate_enabled', 'true');
    SiteSetting::set('cycle_count_frequency', 'daily');
    SiteSetting::set('cycle_count_sample_size', '5');

    $svc = app(CycleCountService::class);
    $result = $svc->generateScheduled();

    expect($result['generated'])->toBeGreaterThanOrEqual(1);
    $session = StockAuditSession::where('warehouse_id', $wh->id)->where('auto_generated', true)->first();
    expect($session)->not->toBeNull()
        ->and($session->items()->count())->toBeLessThanOrEqual(5);
});

test('settings can be updated and retrieved', function () {
    $svc = app(CycleCountService::class);

    $svc->updateSettings([
        'auto_generate_enabled' => true,
        'frequency' => 'monthly',
        'sample_size' => 50,
        'auto_create_adjustments' => false,
    ]);

    $settings = $svc->getSettings();

    expect($settings['auto_generate_enabled'])->toBeTrue()
        ->and($settings['frequency'])->toBe('monthly')
        ->and($settings['sample_size'])->toBe(50)
        ->and($settings['auto_create_adjustments'])->toBeFalse();
});
