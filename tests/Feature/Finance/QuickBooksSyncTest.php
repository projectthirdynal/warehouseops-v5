<?php

use App\Domain\Finance\Models\QboSyncQueue;
use App\Domain\Finance\Services\QboSyncService;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\SiteSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;

function adminUserForQbo(): User
{
    return User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
}

function invoiceWithLines(float $amount = 5000): Invoice
{
    $invoice = Invoice::create([
        'ref' => 'INV-QBO-'.uniqid(),
        'type' => 'standard', 'status' => 'VALIDATED',
        'client_name' => 'QBO Test Client',
        'date_invoice' => now()->toDateString(),
        'date_due' => now()->addDays(30)->toDateString(),
        'payment_terms' => 'NET30', 'currency' => 'PHP',
        'subtotal' => $amount, 'discount_amount' => 0,
        'tax_rate' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
        'total_amount' => $amount, 'amount_paid' => 0,
        'amount_due' => $amount, 'created_by' => adminUserForQbo()->id,
    ]);
    InvoiceLine::create([
        'invoice_id' => $invoice->id, 'description' => 'Test',
        'quantity' => 1, 'unit_price' => $amount,
        'tax_rate' => 0, 'discount_pct' => 0,
        'line_total' => $amount, 'position' => 0,
    ]);

    return $invoice->fresh(['lines']);
}

afterEach(function () {
    SiteSetting::whereIn('key', [
        'qbo_auto_sync_invoice', 'qbo_auto_sync_payment',
        'qbo_auto_sync_bill', 'qbo_auto_sync_bill_payment',
        'qbo_auto_sync_deposit', 'qbo_auto_sync_cogs',
    ])->delete();
});

test('qbo dashboard renders', function () {
    actingAs(adminUserForQbo());
    $this->get('/finance/quickbooks')->assertOk();
});

test('enqueue invoice creates pending sync entry', function () {
    $row = app(QboSyncService::class)->enqueueInvoice(invoiceWithLines(3000));
    expect($row)->not->toBeNull()
        ->and($row->entity_type)->toBe('invoice')
        ->and($row->status)->toBe('PENDING');
});

test('enqueue invoice returns null when disabled', function () {
    SiteSetting::set('qbo_auto_sync_invoice', '0');
    expect(app(QboSyncService::class)->enqueueInvoice(invoiceWithLines(1000)))->toBeNull();
});

test('enqueue payment creates pending sync entry', function () {
    $invoice = invoiceWithLines(2000);
    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id, 'amount' => 2000,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'gcash', 'reference_number' => 'GC-001',
        'recorded_by' => adminUserForQbo()->id,
    ]);
    $row = app(QboSyncService::class)->enqueuePayment($payment);
    expect($row)->not->toBeNull()
        ->and($row->entity_type)->toBe('payment')
        ->and($row->status)->toBe('PENDING');
});

test('enqueue payment returns null when disabled', function () {
    SiteSetting::set('qbo_auto_sync_payment', '0');
    $invoice = invoiceWithLines(1500);
    $payment = InvoicePayment::create([
        'invoice_id' => $invoice->id, 'amount' => 1500,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash', 'recorded_by' => adminUserForQbo()->id,
    ]);
    expect(app(QboSyncService::class)->enqueuePayment($payment))->toBeNull();
});

test('bulk retry re-queues failed items', function () {
    $f1 = QboSyncQueue::create([
        'entity_type' => 'invoice', 'entity_id' => 9991,
        'operation' => 'CREATE', 'status' => 'FAILED',
        'attempts' => 3, 'error_message' => 'err1',
    ]);
    $f2 = QboSyncQueue::create([
        'entity_type' => 'payment', 'entity_id' => 9992,
        'operation' => 'CREATE', 'status' => 'FAILED',
        'attempts' => 3, 'error_message' => 'err2',
    ]);
    $ok = QboSyncQueue::create([
        'entity_type' => 'bill', 'entity_id' => 9993,
        'operation' => 'CREATE', 'status' => 'SYNCED',
        'attempts' => 1, 'synced_at' => now(),
    ]);

    $count = app(QboSyncService::class)->bulkRetry();

    expect($count)->toBe(2)
        ->and($f1->fresh()->status)->toBe('PENDING')
        ->and($f1->fresh()->error_message)->toBeNull()
        ->and($ok->fresh()->status)->toBe('SYNCED');
});

test('get sync settings returns defaults', function () {
    $s = app(QboSyncService::class)->getSyncSettings();
    expect($s['auto_sync_invoice'])->toBeTrue()
        ->and($s['auto_sync_payment'])->toBeTrue()
        ->and($s['auto_sync_bill'])->toBeTrue();
});

test('update sync settings persists', function () {
    app(QboSyncService::class)->updateSyncSettings(['auto_sync_invoice' => false]);
    expect(SiteSetting::get('qbo_auto_sync_invoice'))->toBe('0');
});

test('get entity stats returns counts', function () {
    QboSyncQueue::create([
        'entity_type' => 'invoice', 'entity_id' => 8001,
        'operation' => 'CREATE', 'status' => 'PENDING', 'attempts' => 0,
    ]);
    QboSyncQueue::create([
        'entity_type' => 'invoice', 'entity_id' => 8002,
        'operation' => 'CREATE', 'status' => 'SYNCED',
        'attempts' => 1, 'synced_at' => now(),
    ]);

    $stats = app(QboSyncService::class)->getEntityStats();

    expect($stats['invoice']['pending'])->toBe(1)
        ->and($stats['invoice']['synced'])->toBe(1);
});
