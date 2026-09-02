<?php

use Modules\Finance\Models\PaymentTransaction;
use Modules\Finance\Services\PaymentGatewayService;
use App\Models\Invoice;
use App\Models\SiteSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;

function financeUserForPg(): User
{
    return User::factory()->create(['role' => 'finance', 'is_active' => true]);
}

function adminUserForPg(): User
{
    return User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
}

function unpaidInvoice(float $amount = 1000): Invoice
{
    return Invoice::create([
        'ref' => 'INV-TEST-'.uniqid(),
        'type' => 'standard',
        'status' => 'SENT',
        'client_name' => 'Test Client',
        'date_invoice' => now()->toDateString(),
        'date_due' => now()->addDays(30)->toDateString(),
        'payment_terms' => 'NET30',
        'currency' => 'PHP',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'shipping_amount' => 0,
        'total_amount' => $amount,
        'amount_paid' => 0,
        'amount_due' => $amount,
        'created_by' => adminUserForPg()->id,
    ]);
}

afterEach(function () {
    // Clean up settings
    SiteSetting::whereIn('key', [
        'pg_gcash_enabled', 'pg_gcash_number',
        'pg_bank_transfer_enabled', 'pg_bank_name', 'pg_bank_account_name', 'pg_bank_account_number',
        'pg_maya_enabled', 'pg_maya_number',
        'pg_card_enabled',
        'pg_auto_verify', 'pg_auto_reconcile',
    ])->delete();
});

test('payment gateway page renders', function () {
    actingAs(financeUserForPg());

    $this->get('/finance/payment-gateway')->assertOk();
});

test('record transaction creates pending payment', function () {
    $service = app(PaymentGatewayService::class);

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_GCASH,
        'amount' => 1500,
        'sender_name' => 'Juan Dela Cruz',
        'sender_account' => '09123456789',
    ]);

    expect($transaction->status)->toBe(PaymentTransaction::STATUS_PENDING)
        ->and($transaction->reference_number)->not->toBeEmpty()
        ->and((float) $transaction->amount)->toBe(1500.0)
        ->and($transaction->gateway)->toBe('GCASH');
});

test('verify transaction moves to verified status', function () {
    $service = app(PaymentGatewayService::class);
    $user = adminUserForPg();

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_BANK_TRANSFER,
        'amount' => 5000,
    ]);

    $verified = $service->verifyTransaction($transaction->id, $user->id);

    expect($verified->status)->toBe(PaymentTransaction::STATUS_VERIFIED)
        ->and($verified->verified_by)->toBe($user->id)
        ->and($verified->verified_at)->not->toBeNull();
});

test('cannot verify a non-pending transaction', function () {
    $service = app(PaymentGatewayService::class);
    $user = adminUserForPg();

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_GCASH,
        'amount' => 1000,
    ]);

    $service->verifyTransaction($transaction->id, $user->id);

    expect(fn () => $service->verifyTransaction($transaction->id, $user->id))
        ->toThrow(DomainException::class);
});

test('fail transaction marks as failed with reason', function () {
    $service = app(PaymentGatewayService::class);

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_GCASH,
        'amount' => 500,
    ]);

    $failed = $service->failTransaction($transaction->id, null, 'Customer disputed charge');

    expect($failed->status)->toBe(PaymentTransaction::STATUS_FAILED)
        ->and($failed->notes)->toBe('Customer disputed charge');
});

test('auto-reconcile matches transaction to invoice by amount', function () {
    $invoice = unpaidInvoice(2000);
    $service = app(PaymentGatewayService::class);
    $user = adminUserForPg();

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_GCASH,
        'amount' => 2000,
        'sender_name' => 'Test Client',
    ]);

    $service->verifyTransaction($transaction->id, $user->id);
    $transaction = $service->autoReconcileTransaction($transaction->fresh(), $user->id);

    expect($transaction->status)->toBe(PaymentTransaction::STATUS_RECONCILED)
        ->and($transaction->invoice_id)->toBe($invoice->id);

    $invoice->refresh();
    expect($invoice->status)->toBe('PAID')
        ->and((float) $invoice->amount_paid)->toBe(2000.0);
});

test('reconcile with invoice creates invoice payment and financial transaction', function () {
    $invoice = unpaidInvoice(3000);
    $service = app(PaymentGatewayService::class);
    $user = adminUserForPg();

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_BANK_TRANSFER,
        'amount' => 3000,
    ]);

    $service->verifyTransaction($transaction->id, $user->id);
    $transaction = $service->reconcileWithInvoice($transaction->fresh(), $invoice, $user->id);

    expect($transaction->status)->toBe(PaymentTransaction::STATUS_RECONCILED)
        ->and($transaction->reconciliation_ref)->toBe($invoice->ref);

    $this->assertDatabaseHas('invoice_payments', [
        'invoice_id' => $invoice->id,
        'reference_number' => $transaction->reference_number,
    ]);

    $this->assertDatabaseHas('financial_transactions', [
        'reference_type' => PaymentTransaction::class,
        'reference_id' => $transaction->id,
    ]);
});

test('auto-reconcile with no match leaves transaction verified', function () {
    $service = app(PaymentGatewayService::class);
    $user = adminUserForPg();

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_GCASH,
        'amount' => 777,
    ]);

    $service->verifyTransaction($transaction->id, $user->id);
    $transaction = $service->autoReconcileTransaction($transaction->fresh(), $user->id);

    expect($transaction->status)->toBe(PaymentTransaction::STATUS_VERIFIED);
});

test('get settings returns default values', function () {
    $service = app(PaymentGatewayService::class);
    $settings = $service->getSettings();

    expect($settings['gcash_enabled'])->toBeFalse()
        ->and($settings['bank_transfer_enabled'])->toBeFalse()
        ->and($settings['auto_verify'])->toBeFalse()
        ->and($settings['auto_reconcile'])->toBeFalse();
});

test('update settings persists values', function () {
    $service = app(PaymentGatewayService::class);

    $service->updateSettings([
        'gcash_enabled' => true,
        'gcash_number' => '09123456789',
        'auto_verify' => true,
    ]);

    expect(SiteSetting::get('pg_gcash_enabled'))->toBe('1')
        ->and(SiteSetting::get('pg_gcash_number'))->toBe('09123456789')
        ->and(SiteSetting::get('pg_auto_verify'))->toBe('1');
});

test('get stats returns correct counts', function () {
    $service = app(PaymentGatewayService::class);
    $user = adminUserForPg();

    $t1 = $service->recordTransaction(['gateway' => 'GCASH', 'amount' => 1000]);
    $t2 = $service->recordTransaction(['gateway' => 'GCASH', 'amount' => 2000]);
    $service->verifyTransaction($t2->id, $user->id);

    $stats = $service->getStats();

    expect($stats['pending_count'])->toBe(1)
        ->and($stats['verified_count'])->toBe(1)
        ->and($stats['by_gateway']['GCASH']['count'])->toBe(2);
});

test('auto-verify triggers when setting enabled', function () {
    SiteSetting::set('pg_auto_verify', '1');
    $service = app(PaymentGatewayService::class);

    $transaction = $service->recordTransaction([
        'gateway' => PaymentTransaction::GATEWAY_GCASH,
        'amount' => 500,
    ]);

    expect($transaction->fresh()->status)->toBe(PaymentTransaction::STATUS_VERIFIED);
});

test('store via web route', function () {
    actingAs(adminUserForPg());

    $this
        ->post('/finance/payment-gateway', [
            'gateway' => 'GCASH',
            'amount' => 1500,
            'sender_name' => 'Test Sender',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('payment_transactions', [
        'gateway' => 'GCASH',
        'amount' => 1500,
        'status' => 'PENDING',
    ]);
});

test('verify via web route', function () {
    $service = app(PaymentGatewayService::class);
    $transaction = $service->recordTransaction(['gateway' => 'GCASH', 'amount' => 1000]);

    actingAs(adminUserForPg());

    $this
        ->post("/finance/payment-gateway/{$transaction->id}/verify")
        ->assertRedirect();

    $transaction->refresh();
    expect($transaction->status)->toBe(PaymentTransaction::STATUS_VERIFIED);
});

test('reconcile via web route with invoice_id', function () {
    $invoice = unpaidInvoice(2500);
    $service = app(PaymentGatewayService::class);
    $user = adminUserForPg();

    $transaction = $service->recordTransaction(['gateway' => 'GCASH', 'amount' => 2500]);
    $service->verifyTransaction($transaction->id, $user->id);

    actingAs($user);

    $this
        ->post("/finance/payment-gateway/{$transaction->id}/reconcile", [
            'invoice_id' => $invoice->id,
        ])
        ->assertRedirect();

    $transaction->refresh();
    expect($transaction->status)->toBe(PaymentTransaction::STATUS_RECONCILED);
});

test('update settings via web route', function () {
    actingAs(adminUserForPg());

    $this
        ->patch('/finance/payment-gateway/settings', [
            'gcash_enabled' => true,
            'gcash_number' => '09180000000',
        ])
        ->assertRedirect();

    expect(SiteSetting::get('pg_gcash_enabled'))->toBe('1')
        ->and(SiteSetting::get('pg_gcash_number'))->toBe('09180000000');
});
