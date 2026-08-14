<?php

use App\Domain\Finance\Models\AgentCommission;
use App\Domain\Finance\Models\CommissionRule;
use App\Domain\Finance\Models\CommissionRun;
use App\Domain\Finance\Services\CommissionService;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;

function financeUser(): User
{
    return User::factory()->create(['role' => 'finance', 'is_active' => true]);
}

function adminUser(): User
{
    return User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
}

function agentUser(): User
{
    return User::factory()->create(['role' => 'agent', 'is_active' => true]);
}

function commissionProduct(): Product
{
    $product = Product::factory()->create(['is_active' => true]);

    CommissionRule::create([
        'product_id' => null,
        'rate_type' => 'PERCENTAGE',
        'rate_value' => 5.00,
        'is_active' => true,
    ]);

    return $product;
}

function deliveredOrderWithAgent(User $agent, Product $product, float $amount = 1000): Order
{
    return Order::create([
        'order_number' => 'ORD-'.uniqid(),
        'assigned_agent_id' => $agent->id,
        'product_id' => $product->id,
        'status' => OrderStatus::DELIVERED,
        'quantity' => 1,
        'unit_price' => $amount,
        'total_amount' => $amount,
        'receiver_name' => 'Test Customer',
        'receiver_phone' => '09123456789',
        'receiver_address' => 'Test Address',
        'delivered_at' => now(),
    ]);
}

beforeEach(function () {
    SiteSetting::set('commission_auto_generate', '0');
    SiteSetting::set('commission_run_frequency', 'MONTHLY');
    SiteSetting::set('commission_require_approval', '1');
    SiteSetting::set('commission_auto_approve_threshold', '0');
    SiteSetting::set('commission_min_amount', '0');
});

afterEach(function () {
    SiteSetting::set('commission_auto_generate', '0');
});

test('commission automation page renders', function () {
    actingAs(financeUser());

    $this->get('/finance/commission-automation')->assertOk();
});

test('create run groups pending commissions', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    $user = adminUser();
    $run = $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);

    expect($run)->toBeInstanceOf(CommissionRun::class)
        ->and($run->status)->toBe(CommissionRun::STATUS_PENDING_APPROVAL)
        ->and($run->commission_count)->toBe(1)
        ->and((float) $run->total_amount)->toBe(50.0);

    $commission = AgentCommission::where('order_id', $order->id)->first();
    expect($commission->commission_run_id)->toBe($run->id);
});

test('approve run moves commissions to approved', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    $user = adminUser();
    $run = $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);
    $run = $service->approveRun($run, $user->id);

    expect($run->status)->toBe(CommissionRun::STATUS_APPROVED)
        ->and($run->approved_by)->toBe($user->id);

    $commission = AgentCommission::where('order_id', $order->id)->first();
    expect($commission->status)->toBe('APPROVED');
});

test('reject run unlinks commissions and keeps them pending', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    $user = adminUser();
    $run = $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);
    $run = $service->rejectRun($run, $user->id, 'Incorrect rates');

    expect($run->status)->toBe(CommissionRun::STATUS_REJECTED)
        ->and($run->rejection_reason)->toBe('Incorrect rates');

    $commission = AgentCommission::where('order_id', $order->id)->first();
    expect($commission->status)->toBe('PENDING')
        ->and($commission->commission_run_id)->toBeNull();
});

test('pay run marks commissions as paid and creates transactions', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    $user = adminUser();
    $run = $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);
    $run = $service->approveRun($run, $user->id);
    $run = $service->payRun($run, $user->id);

    expect($run->status)->toBe(CommissionRun::STATUS_PAID)
        ->and($run->paid_by)->toBe($user->id);

    $commission = AgentCommission::where('order_id', $order->id)->first();
    expect($commission->status)->toBe('PAID')
        ->and($commission->paid_at)->not->toBeNull();

    $this->assertDatabaseHas('financial_transactions', [
        'type' => 'COMMISSION',
        'reference_id' => $commission->id,
        'reference_type' => AgentCommission::class,
    ]);
});

test('reject individual commission', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $commission = $service->createForOrder($order);

    $service->rejectCommission($commission->id, 'Duplicate order');

    $commission->refresh();
    expect($commission->status)->toBe('REJECTED')
        ->and($commission->rejection_reason)->toBe('Duplicate order');
});

test('cannot approve run that is not pending approval', function () {
    $user = adminUser();
    $run = CommissionRun::create([
        'name' => 'Test Run',
        'period_type' => 'MONTHLY',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => CommissionRun::STATUS_APPROVED,
        'commission_count' => 0,
        'total_amount' => 0,
        'created_by' => $user->id,
    ]);

    $service = app(CommissionService::class);

    expect(fn () => $service->approveRun($run, $user->id))
        ->toThrow(DomainException::class);
});

test('cannot pay run that is not approved', function () {
    $user = adminUser();
    $run = CommissionRun::create([
        'name' => 'Test Run',
        'period_type' => 'MONTHLY',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => CommissionRun::STATUS_PENDING_APPROVAL,
        'commission_count' => 0,
        'total_amount' => 0,
        'created_by' => $user->id,
    ]);

    $service = app(CommissionService::class);

    expect(fn () => $service->payRun($run, $user->id))
        ->toThrow(DomainException::class);
});

test('backfill creates commissions for delivered orders missing them', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    // No commission created yet — simulate missed commission
    $service = app(CommissionService::class);
    $result = $service->backfillMissedCommissions();

    expect($result['created'])->toBe(1);

    $this->assertDatabaseHas('agent_commissions', [
        'order_id' => $order->id,
        'agent_id' => $agent->id,
    ]);
});

test('settings can be updated and retrieved', function () {
    $service = app(CommissionService::class);

    $service->updateSettings([
        'frequency' => 'WEEKLY',
        'auto_generate_enabled' => true,
        'require_approval' => false,
        'auto_approve_threshold' => 500,
    ]);

    $settings = $service->getSettings();

    expect($settings['frequency'])->toBe('WEEKLY')
        ->and($settings['auto_generate_enabled'])->toBeTrue()
        ->and($settings['require_approval'])->toBeFalse()
        ->and($settings['auto_approve_threshold'])->toBe(500.0);
});

test('run stats return correct counts', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    $user = adminUser();
    $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);

    $stats = $service->getRunStats();

    expect($stats['total_runs'])->toBe(1)
        ->and($stats['pending_approval'])->toBe(1)
        ->and($stats['unassigned_pending'])->toBe(0);
});

test('agent breakdown groups by agent correctly', function () {
    $agent1 = agentUser();
    $agent2 = User::factory()->create(['role' => 'agent', 'is_active' => true]);
    $product = commissionProduct();

    $order1 = deliveredOrderWithAgent($agent1, $product, 1000);
    $order2 = deliveredOrderWithAgent($agent2, $product, 2000);

    $service = app(CommissionService::class);
    $service->createForOrder($order1);
    $service->createForOrder($order2);

    $user = adminUser();
    $run = $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);

    $breakdown = $service->getRunAgentBreakdown($run);

    expect($breakdown)->toHaveCount(2);
    $names = $breakdown->pluck('agent_name')->toArray();
    expect($names)->toContain($agent1->name)->toContain($agent2->name);
});

test('run detail page renders', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    $user = adminUser();
    $run = $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);

    actingAs(financeUser());

    $this->get("/finance/commission-automation/{$run->id}")->assertOk();
});

test('create run via web route', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    actingAs(adminUser());

    $this
        ->post('/finance/commission-automation', [
            'period_type' => 'MONTHLY',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('commission_runs', [
        'period_type' => 'MONTHLY',
        'status' => CommissionRun::STATUS_PENDING_APPROVAL,
    ]);
});

test('approve run via web route', function () {
    $agent = agentUser();
    $product = commissionProduct();
    $order = deliveredOrderWithAgent($agent, $product);

    $service = app(CommissionService::class);
    $service->createForOrder($order);

    $user = adminUser();
    $run = $service->createRun(CommissionRun::PERIOD_MONTHLY, null, null, $user->id);

    actingAs($user);

    $this
        ->post("/finance/commission-automation/{$run->id}/approve")
        ->assertRedirect();

    $run->refresh();
    expect($run->status)->toBe(CommissionRun::STATUS_APPROVED);
});

test('update settings via web route', function () {
    actingAs(adminUser());

    $this
        ->patch('/finance/commission-automation/settings', [
            'frequency' => 'WEEKLY',
            'auto_generate_enabled' => true,
            'require_approval' => true,
            'auto_approve_threshold' => 1000,
            'min_commission_amount' => 10,
        ])
        ->assertRedirect();

    expect(SiteSetting::get('commission_run_frequency'))->toBe('WEEKLY');
    expect(SiteSetting::get('commission_auto_generate'))->toBe('1');
});
