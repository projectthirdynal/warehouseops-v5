<?php

use Modules\Finance\Models\CodReconciliationItem;
use Modules\Finance\Models\CodSettlement;
use Modules\Finance\Services\CodReconciliationService;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Waybills\Enums\WaybillStatus;
use Modules\Waybills\Models\Waybill;
use App\Models\User;

use function Pest\Laravel\actingAs;

function financeUserForCod(): User
{
    return User::factory()->create(['role' => 'finance', 'is_active' => true]);
}

function adminUserForCod(): User
{
    return User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
}

function codSettlement(array $overrides = []): CodSettlement
{
    return CodSettlement::create(array_merge([
        'courier_code' => 'FLASH',
        'reference_number' => 'REF-'.uniqid(),
        'period_start' => now()->subDays(7)->toDateString(),
        'period_end' => now()->toDateString(),
        'total_cod_collected' => 3000,
        'courier_fee' => 150,
        'net_amount' => 2850,
        'order_count' => 3,
        'status' => 'RECEIVED',
        'received_at' => now(),
    ], $overrides));
}

function deliveredCodOrder(float $codAmount = 1000, string $courier = 'FLASH'): Order
{
    return Order::create([
        'order_number' => 'ORD-'.uniqid(),
        'courier_code' => $courier,
        'status' => OrderStatus::DELIVERED,
        'quantity' => 1,
        'unit_price' => $codAmount,
        'total_amount' => $codAmount,
        'cod_amount' => $codAmount,
        'receiver_name' => 'Test Customer',
        'receiver_phone' => '09123456789',
        'receiver_address' => 'Test Address',
        'delivered_at' => now()->subDays(3),
    ]);
}

function deliveredWaybill(float $amount = 1000, string $courier = 'FLASH'): Waybill
{
    return Waybill::create([
        'waybill_number' => 'WB-'.uniqid(),
        'status' => WaybillStatus::DELIVERED,
        'receiver_name' => 'Test Customer',
        'receiver_phone' => '09123456789',
        'receiver_address' => 'Test Address',
        'item_qty' => 1,
        'amount' => $amount,
        'courier_provider' => $courier,
        'delivered_at' => now()->subDays(3),
    ]);
}

test('cod reconciliation page renders', function () {
    actingAs(financeUserForCod());

    $this->get('/finance/cod-reconciliation')->assertOk();
});

test('auto-match creates reconciliation items from delivered orders', function () {
    $order1 = deliveredCodOrder(1000);
    $order2 = deliveredCodOrder(2000);
    $settlement = codSettlement(['total_cod_collected' => 3000]);

    $service = app(CodReconciliationService::class);
    $result = $service->autoMatch($settlement);

    expect($result['orders_found'])->toBe(2)
        ->and($result['matched'])->toBe(2);

    $settlement->refresh();
    expect((float) $settlement->expected_cod)->toBe(3000.0)
        ->and($settlement->matched_count)->toBe(2)
        ->and($settlement->unmatched_count)->toBe(0);

    $items = $settlement->reconciliationItems;
    expect($items)->toHaveCount(2);
    expect($items->pluck('order_id')->toArray())->toContain($order1->id, $order2->id);
});

test('auto-match detects variance when collected differs from expected', function () {
    deliveredCodOrder(1000);
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 2500]);

    $service = app(CodReconciliationService::class);
    $result = $service->autoMatch($settlement);

    expect((float) $result['variance'])->toBe(500.0);

    $settlement->refresh();
    expect((float) $settlement->variance)->toBe(500.0);
});

test('auto-match includes orphan waybills without linked orders', function () {
    $order = deliveredCodOrder(1000);
    $waybill = deliveredWaybill(500);
    $settlement = codSettlement(['total_cod_collected' => 1500]);

    $service = app(CodReconciliationService::class);
    $result = $service->autoMatch($settlement);

    expect($result['orders_found'])->toBe(1)
        ->and($result['waybills_found'])->toBe(1);

    $items = $settlement->fresh()->reconciliationItems;
    expect($items)->toHaveCount(2);
    expect($items->pluck('waybill_id')->filter())->toHaveCount(1);
});

test('manual match links item to order', function () {
    $order = deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    $item = $settlement->reconciliationItems()->first();

    // Unmatch it first
    $service->unmatch($item->id);
    $item->refresh();
    expect($item->match_status)->toBe(CodReconciliationItem::MATCH_STATUS_UNMATCHED);

    // Manual match
    $matched = $service->manualMatch($item->id, $order->id);
    expect($matched->match_status)->toBe(CodReconciliationItem::MATCH_STATUS_MANUAL_MATCH)
        ->and($matched->order_id)->toBe($order->id)
        ->and($matched->match_type)->toBe(CodReconciliationItem::MATCH_TYPE_MANUAL);
});

test('unmatch resets item to unmatched status', function () {
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    $item = $settlement->reconciliationItems()->first();
    expect($item->match_status)->not->toBe(CodReconciliationItem::MATCH_STATUS_UNMATCHED);

    $service->unmatch($item->id);
    $item->refresh();

    expect($item->match_status)->toBe(CodReconciliationItem::MATCH_STATUS_UNMATCHED)
        ->and($item->order_id)->toBeNull()
        ->and((float) $item->remitted_cod)->toBe(0.0);
});

test('finalize marks settlement reconciled and creates financial transactions', function () {
    deliveredCodOrder(1000);
    deliveredCodOrder(2000);
    $settlement = codSettlement(['total_cod_collected' => 3000, 'courier_fee' => 150]);

    $user = adminUserForCod();
    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    $settlement = $service->finalize($settlement, $user->id);

    expect($settlement->status)->toBe('RECONCILED')
        ->and($settlement->reconciled_by)->toBe($user->id);

    $this->assertDatabaseHas('financial_transactions', [
        'type' => 'COD_COLLECTION',
        'reference_type' => CodSettlement::class,
        'reference_id' => $settlement->id,
    ]);

    $this->assertDatabaseHas('financial_transactions', [
        'type' => 'SHIPPING_COST',
        'reference_type' => CodSettlement::class,
        'reference_id' => $settlement->id,
    ]);
});

test('cannot finalize with unmatched items', function () {
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 500]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    // Unmatch one item
    $item = $settlement->reconciliationItems()->first();
    $service->unmatch($item->id);

    expect(fn () => $service->finalize($settlement, adminUserForCod()->id))
        ->toThrow(DomainException::class);
});

test('cannot auto-match a pending settlement', function () {
    $settlement = codSettlement(['status' => 'PENDING', 'received_at' => null]);

    $service = app(CodReconciliationService::class);

    expect(fn () => $service->autoMatch($settlement))
        ->toThrow(DomainException::class);
});

test('reconciliation stats return correct values', function () {
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);
    $service->finalize($settlement, adminUserForCod()->id);

    $stats = $service->getStats();

    expect($stats['reconciled_count'])->toBeGreaterThanOrEqual(1)
        ->and($stats['unmatched_items'])->toBe(0);
});

test('reconciliation detail page renders', function () {
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    actingAs(financeUserForCod());

    $this->get("/finance/cod-reconciliation/{$settlement->id}")->assertOk();
});

test('auto-match via web route', function () {
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    actingAs(adminUserForCod());

    $this
        ->post("/finance/cod-reconciliation/{$settlement->id}/auto-match")
        ->assertRedirect();

    $settlement->refresh();
    expect($settlement->matched_count)->toBe(1);
});

test('finalize via web route', function () {
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    actingAs(adminUserForCod());

    $this
        ->post("/finance/cod-reconciliation/{$settlement->id}/finalize")
        ->assertRedirect();

    $settlement->refresh();
    expect($settlement->status)->toBe('RECONCILED');
});

test('manual match via web route', function () {
    $order = deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    $item = $settlement->reconciliationItems()->first();
    $service->unmatch($item->id);

    actingAs(adminUserForCod());

    $this
        ->post('/finance/cod-reconciliation/manual-match', [
            'item_id' => $item->id,
            'order_id' => $order->id,
        ])
        ->assertRedirect();

    $item->refresh();
    expect($item->match_status)->toBe(CodReconciliationItem::MATCH_STATUS_MANUAL_MATCH);
});

test('unmatch via web route', function () {
    deliveredCodOrder(1000);
    $settlement = codSettlement(['total_cod_collected' => 1000]);

    $service = app(CodReconciliationService::class);
    $service->autoMatch($settlement);

    $item = $settlement->reconciliationItems()->first();

    actingAs(adminUserForCod());

    $this
        ->post('/finance/cod-reconciliation/unmatch', [
            'item_id' => $item->id,
        ])
        ->assertRedirect();

    $item->refresh();
    expect($item->match_status)->toBe(CodReconciliationItem::MATCH_STATUS_UNMATCHED);
});
