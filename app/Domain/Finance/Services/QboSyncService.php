<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Jobs\QboSyncJob;
use App\Domain\Finance\Models\CogsEntry;
use App\Domain\Finance\Models\QboAccountMapping;
use App\Domain\Finance\Models\QboSyncQueue;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\ReceivingReport;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SiteSetting;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds QBO payloads from internal records and enqueues them for the
 * async QboSyncJob. Each enqueue creates a row with a unique idempotency_key
 * passed as RequestId to QBO — retries never duplicate.
 */
class QboSyncService
{
    /**
     * Bill from a confirmed GRN against a PO.
     * One Bill per GRN (sums received line totals × unit price from PO).
     */
    public function enqueueBillFromGrn(ReceivingReport $grn): ?QboSyncQueue
    {
        $po = $grn->purchaseOrder()->with('items.product', 'supplier')->first();
        if (! $po) {
            return null;
        }

        $vendorId = $po->supplier->qbo_vendor_id ?? null;
        if (! $vendorId) {
            // Vendor must be synced first; we'll enqueue the vendor sync and let user retry the bill later
            $this->enqueueVendor($po->supplier);

            return null;
        }

        $apAccount = $this->requireMapping('accounts_payable');
        $inventoryAccount = $this->requireMapping('inventory_asset');

        $lines = [];
        foreach ($grn->items as $g) {
            $poItem = $g->purchaseOrderItem;
            $amount = (float) $g->quantity_received * (float) ($poItem->unit_price ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'Amount' => round($amount, 2),
                'DetailType' => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef' => ['value' => $inventoryAccount->qbo_account_id],
                ],
                'Description' => trim(($poItem->product?->sku ?? '').' '.($poItem->product?->name ?? '')),
            ];
        }
        if (empty($lines)) {
            return null;
        }

        $payload = [
            'VendorRef' => ['value' => $vendorId],
            'TxnDate' => $grn->received_at?->toDateString(),
            'DocNumber' => $grn->grn_number,
            'CurrencyRef' => ['value' => $po->currency_code],
            'APAccountRef' => ['value' => $apAccount->qbo_account_id],
            'PrivateNote' => "GRN {$grn->grn_number} against PO {$po->po_number}",
            'Line' => $lines,
        ];

        return QboSyncQueue::create([
            'entity_type' => 'bill',
            'entity_id' => $grn->id,
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ])->tap(fn ($row) => QboSyncJob::dispatch($row->id));
    }

    /**
     * Journal entry on COGS recording.
     * Debit COGS, credit Inventory Asset for each lot consumed.
     */
    public function enqueueCogsJournal(Collection $cogsEntries, ?int $waybillId = null): ?QboSyncQueue
    {
        if ($cogsEntries->isEmpty()) {
            return null;
        }

        $cogsAccount = $this->requireMapping('cogs');
        $inventoryAccount = $this->requireMapping('inventory_asset');

        $totalCost = (float) $cogsEntries->sum('total_cost');
        if ($totalCost <= 0) {
            return null;
        }

        $payload = [
            'TxnDate' => now()->toDateString(),
            'DocNumber' => 'COGS-'.($waybillId ?? $cogsEntries->first()->id),
            'PrivateNote' => 'COGS for waybill '.($waybillId ?? 'n/a').' ('.$cogsEntries->count().' lots)',
            'Line' => [
                [
                    'Description' => 'COGS',
                    'Amount' => round($totalCost, 2),
                    'DetailType' => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Debit',
                        'AccountRef' => ['value' => $cogsAccount->qbo_account_id],
                    ],
                ],
                [
                    'Description' => 'Inventory reduction',
                    'Amount' => round($totalCost, 2),
                    'DetailType' => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Credit',
                        'AccountRef' => ['value' => $inventoryAccount->qbo_account_id],
                    ],
                ],
            ],
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'journal_entry',
            'entity_id' => (int) ($waybillId ?? $cogsEntries->first()->id),
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ]);

        // Mark the cogs entries as queued
        CogsEntry::whereIn('id', $cogsEntries->pluck('id'))->update(['synced_to_qbo_at' => now()]);

        QboSyncJob::dispatch($row->id);

        return $row;
    }

    /**
     * Bank deposit when COD settlement is received.
     */
    public function enqueueDeposit(int $codSettlementId, float $amount, string $reference, ?string $depositDate = null): QboSyncQueue
    {
        $bankAccount = $this->requireMapping('bank_account');
        $undeposited = QboAccountMapping::for('undeposited_funds');

        $payload = [
            'DepositToAccountRef' => ['value' => $bankAccount->qbo_account_id],
            'TxnDate' => $depositDate ?? now()->toDateString(),
            'PrivateNote' => "COD settlement {$reference}",
            'Line' => [
                [
                    'Amount' => round($amount, 2),
                    'DetailType' => 'DepositLineDetail',
                    'DepositLineDetail' => [
                        'AccountRef' => ['value' => $undeposited?->qbo_account_id ?? $bankAccount->qbo_account_id],
                    ],
                    'Description' => "COD: {$reference}",
                ],
            ],
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'deposit',
            'entity_id' => $codSettlementId,
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ]);
        QboSyncJob::dispatch($row->id);

        return $row;
    }

    /**
     * Sync a supplier as a Vendor in QBO. Updates supplier.qbo_vendor_id on success.
     */
    public function enqueueVendor($supplier): QboSyncQueue
    {
        $payload = [
            'DisplayName' => $supplier->name,
            'CompanyName' => $supplier->name,
            'PrimaryEmailAddr' => $supplier->email ? ['Address' => $supplier->email] : null,
            'PrimaryPhone' => $supplier->phone ? ['FreeFormNumber' => $supplier->phone] : null,
            'BillAddr' => $supplier->address ? ['Line1' => $supplier->address] : null,
        ];
        $payload = array_filter($payload, fn ($v) => $v !== null);

        $row = QboSyncQueue::create([
            'entity_type' => 'vendor',
            'entity_id' => $supplier->id,
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ]);
        QboSyncJob::dispatch($row->id);

        return $row;
    }

    /**
     * Push a PO to QBO when SENT.
     */
    public function enqueuePurchaseOrder(PurchaseOrder $po): ?QboSyncQueue
    {
        $vendorId = $po->supplier->qbo_vendor_id ?? null;
        if (! $vendorId) {
            $this->enqueueVendor($po->supplier);

            return null;
        }

        $lines = $po->items->map(function ($it) {
            return [
                'Amount' => round((float) $it->line_total, 2),
                'DetailType' => 'ItemBasedExpenseLineDetail',
                'ItemBasedExpenseLineDetail' => [
                    'Qty' => (int) $it->quantity_ordered,
                    'UnitPrice' => round((float) $it->unit_price, 4),
                    'TaxCodeRef' => ['value' => 'NON'],
                ],
                'Description' => trim(($it->product?->sku ?? '').' '.($it->product?->name ?? '')),
            ];
        })->toArray();

        $payload = [
            'VendorRef' => ['value' => $vendorId],
            'TxnDate' => $po->sent_at?->toDateString() ?? now()->toDateString(),
            'DocNumber' => $po->po_number,
            'CurrencyRef' => ['value' => $po->currency_code],
            'PrivateNote' => $po->notes,
            'Line' => $lines,
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'purchase_order',
            'entity_id' => $po->id,
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ]);
        QboSyncJob::dispatch($row->id);

        return $row;
    }

    public function enqueueInvoice(Invoice $invoice): ?QboSyncQueue
    {
        if (! $this->shouldSync('invoice')) {
            return null;
        }

        $lines = [];
        foreach ($invoice->lines as $line) {
            $lines[] = [
                'Amount' => round((float) $line->line_total, 2),
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => '1'],
                    'UnitPrice' => round((float) $line->unit_price, 4),
                    'Qty' => (int) $line->quantity,
                    'TaxCodeRef' => ['value' => 'NON'],
                ],
                'Description' => $line->description ?? '',
            ];
        }
        if (empty($lines)) {
            return null;
        }

        $payload = [
            'DocNumber' => $invoice->ref,
            'TxnDate' => $invoice->date_invoice?->toDateString() ?? now()->toDateString(),
            'DueDate' => $invoice->date_due?->toDateString(),
            'CustomerRef' => ['value' => '1'],
            'CurrencyRef' => ['value' => $invoice->currency ?? 'PHP'],
            'PrivateNote' => "Invoice {$invoice->ref} — {$invoice->client_name}",
            'Line' => $lines,
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ]);
        QboSyncJob::dispatch($row->id);

        return $row;
    }

    public function enqueuePayment(InvoicePayment $payment): ?QboSyncQueue
    {
        if (! $this->shouldSync('payment')) {
            return null;
        }

        $invoice = $payment->invoice;
        if (! $invoice) {
            return null;
        }

        $undeposited = QboAccountMapping::for('undeposited_funds');
        $bankAccount = QboAccountMapping::for('bank_account');

        $payload = [
            'TxnDate' => $payment->payment_date?->toDateString() ?? now()->toDateString(),
            'TotalAmt' => round((float) $payment->amount, 2),
            'CurrencyRef' => ['value' => $invoice->currency ?? 'PHP'],
            'PrivateNote' => "Payment for {$invoice->ref} — Ref: {$payment->reference_number}",
            'Line' => [
                [
                    'Amount' => round((float) $payment->amount, 2),
                    'DetailType' => 'PaymentLineDetail',
                    'PaymentLineDetail' => [
                        'AccountRef' => ['value' => $undeposited?->qbo_account_id ?? $bankAccount?->qbo_account_id ?? '1'],
                    ],
                ],
            ],
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ]);
        QboSyncJob::dispatch($row->id);

        return $row;
    }

    public function enqueueBillPayment(int $billId, float $amount, string $reference, ?string $paymentDate = null): ?QboSyncQueue
    {
        if (! $this->shouldSync('bill_payment')) {
            return null;
        }

        $bankAccount = $this->requireMapping('bank_account');
        $apAccount = $this->requireMapping('accounts_payable');

        $payload = [
            'TotalAmt' => round($amount, 2),
            'TxnDate' => $paymentDate ?? now()->toDateString(),
            'PayType' => 'Check',
            'CheckPayment' => [
                'BankAccountRef' => ['value' => $bankAccount->qbo_account_id],
            ],
            'APAccountRef' => ['value' => $apAccount->qbo_account_id],
            'PrivateNote' => "Bill payment — Ref: {$reference}",
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'bill_payment',
            'entity_id' => $billId,
            'operation' => 'CREATE',
            'status' => 'PENDING',
            'payload' => $payload,
        ]);
        QboSyncJob::dispatch($row->id);

        return $row;
    }

    public function bulkRetry(): int
    {
        $failed = QboSyncQueue::failed()->get();
        $count = 0;
        foreach ($failed as $row) {
            $row->update(['status' => 'PENDING', 'error_message' => null]);
            QboSyncJob::dispatch($row->id);
            $count++;
        }

        return $count;
    }

    public function getSyncSettings(): array
    {
        return [
            'auto_sync_invoice' => SiteSetting::get('qbo_auto_sync_invoice', '1') === '1',
            'auto_sync_payment' => SiteSetting::get('qbo_auto_sync_payment', '1') === '1',
            'auto_sync_bill' => SiteSetting::get('qbo_auto_sync_bill', '1') === '1',
            'auto_sync_bill_payment' => SiteSetting::get('qbo_auto_sync_bill_payment', '1') === '1',
            'auto_sync_deposit' => SiteSetting::get('qbo_auto_sync_deposit', '1') === '1',
            'auto_sync_cogs' => SiteSetting::get('qbo_auto_sync_cogs', '1') === '1',
        ];
    }

    public function updateSyncSettings(array $settings): void
    {
        $keys = [
            'auto_sync_invoice' => 'qbo_auto_sync_invoice',
            'auto_sync_payment' => 'qbo_auto_sync_payment',
            'auto_sync_bill' => 'qbo_auto_sync_bill',
            'auto_sync_bill_payment' => 'qbo_auto_sync_bill_payment',
            'auto_sync_deposit' => 'qbo_auto_sync_deposit',
            'auto_sync_cogs' => 'qbo_auto_sync_cogs',
        ];
        foreach ($keys as $field => $key) {
            if (array_key_exists($field, $settings)) {
                SiteSetting::set($key, $settings[$field] ? '1' : '0');
            }
        }
    }

    public function getEntityStats(): array
    {
        $types = ['bill', 'invoice', 'payment', 'bill_payment', 'journal_entry', 'deposit', 'vendor', 'purchase_order'];
        $stats = [];
        foreach ($types as $type) {
            $stats[$type] = [
                'pending' => QboSyncQueue::where('entity_type', $type)->where('status', 'PENDING')->count(),
                'synced' => QboSyncQueue::where('entity_type', $type)->where('status', 'SYNCED')->count(),
                'failed' => QboSyncQueue::where('entity_type', $type)->where('status', 'FAILED')->count(),
            ];
        }

        return $stats;
    }

    private function shouldSync(string $entityType): bool
    {
        $key = "qbo_auto_sync_{$entityType}";

        return SiteSetting::get($key, '1') === '1';
    }

    private function requireMapping(string $key): QboAccountMapping
    {
        $m = QboAccountMapping::for($key);
        if (! $m) {
            throw new RuntimeException("Missing QBO account mapping for '{$key}'. Map it on /finance/account-mappings.");
        }

        return $m;
    }
}
