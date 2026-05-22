<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Jobs\QboSyncJob;
use App\Domain\Finance\Models\CogsEntry;
use App\Domain\Finance\Models\QboAccountMapping;
use App\Domain\Finance\Models\QboSyncQueue;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\ReceivingReport;
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
        if (! $po) return null;

        $vendorId = $po->supplier->qbo_vendor_id ?? null;
        if (! $vendorId) {
            // Vendor must be synced first; we'll enqueue the vendor sync and let user retry the bill later
            $this->enqueueVendor($po->supplier);
            return null;
        }

        $apAccount        = $this->requireMapping('accounts_payable');
        $inventoryAccount = $this->requireMapping('inventory_asset');

        $lines = [];
        foreach ($grn->items as $g) {
            $poItem = $g->purchaseOrderItem;
            $amount = (float) $g->quantity_received * (float) ($poItem->unit_price ?? 0);
            if ($amount <= 0) continue;

            $lines[] = [
                'Amount'            => round($amount, 2),
                'DetailType'        => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef' => ['value' => $inventoryAccount->qbo_account_id],
                ],
                'Description' => trim(($poItem->product?->sku ?? '') . ' ' . ($poItem->product?->name ?? '')),
            ];
        }
        if (empty($lines)) return null;

        $payload = [
            'VendorRef'   => ['value' => $vendorId],
            'TxnDate'     => $grn->received_at?->toDateString(),
            'DocNumber'   => $grn->grn_number,
            'CurrencyRef' => ['value' => $po->currency_code],
            'APAccountRef'=> ['value' => $apAccount->qbo_account_id],
            'PrivateNote' => "GRN {$grn->grn_number} against PO {$po->po_number}",
            'Line'        => $lines,
        ];

        return QboSyncQueue::create([
            'entity_type' => 'bill',
            'entity_id'   => $grn->id,
            'operation'   => 'CREATE',
            'status'      => 'PENDING',
            'payload'     => $payload,
        ])->tap(fn ($row) => QboSyncJob::dispatch($row->id));
    }

    /**
     * Journal entry on COGS recording.
     * Debit COGS, credit Inventory Asset for each lot consumed.
     */
    public function enqueueCogsJournal(Collection $cogsEntries, ?int $waybillId = null): ?QboSyncQueue
    {
        if ($cogsEntries->isEmpty()) return null;

        $cogsAccount      = $this->requireMapping('cogs');
        $inventoryAccount = $this->requireMapping('inventory_asset');

        $totalCost = (float) $cogsEntries->sum('total_cost');
        if ($totalCost <= 0) return null;

        $payload = [
            'TxnDate'   => now()->toDateString(),
            'DocNumber' => 'COGS-' . ($waybillId ?? $cogsEntries->first()->id),
            'PrivateNote' => 'COGS for waybill ' . ($waybillId ?? 'n/a') . ' (' . $cogsEntries->count() . ' lots)',
            'Line' => [
                [
                    'Description' => 'COGS',
                    'Amount'      => round($totalCost, 2),
                    'DetailType'  => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Debit',
                        'AccountRef'  => ['value' => $cogsAccount->qbo_account_id],
                    ],
                ],
                [
                    'Description' => 'Inventory reduction',
                    'Amount'      => round($totalCost, 2),
                    'DetailType'  => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Credit',
                        'AccountRef'  => ['value' => $inventoryAccount->qbo_account_id],
                    ],
                ],
            ],
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'journal_entry',
            'entity_id'   => (int) ($waybillId ?? $cogsEntries->first()->id),
            'operation'   => 'CREATE',
            'status'      => 'PENDING',
            'payload'     => $payload,
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
        $bankAccount    = $this->requireMapping('bank_account');
        $undeposited    = QboAccountMapping::for('undeposited_funds');

        $payload = [
            'DepositToAccountRef' => ['value' => $bankAccount->qbo_account_id],
            'TxnDate'             => $depositDate ?? now()->toDateString(),
            'PrivateNote'         => "COD settlement {$reference}",
            'Line' => [
                [
                    'Amount'     => round($amount, 2),
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
            'entity_id'   => $codSettlementId,
            'operation'   => 'CREATE',
            'status'      => 'PENDING',
            'payload'     => $payload,
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
            'DisplayName'   => $supplier->name,
            'CompanyName'   => $supplier->name,
            'PrimaryEmailAddr' => $supplier->email ? ['Address' => $supplier->email] : null,
            'PrimaryPhone'   => $supplier->phone ? ['FreeFormNumber' => $supplier->phone] : null,
            'BillAddr'       => $supplier->address ? ['Line1' => $supplier->address] : null,
        ];
        $payload = array_filter($payload, fn ($v) => $v !== null);

        $row = QboSyncQueue::create([
            'entity_type' => 'vendor',
            'entity_id'   => $supplier->id,
            'operation'   => 'CREATE',
            'status'      => 'PENDING',
            'payload'     => $payload,
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
                'Amount'      => round((float) $it->line_total, 2),
                'DetailType'  => 'ItemBasedExpenseLineDetail',
                'ItemBasedExpenseLineDetail' => [
                    'Qty'           => (int) $it->quantity_ordered,
                    'UnitPrice'     => round((float) $it->unit_price, 4),
                    'TaxCodeRef'    => ['value' => 'NON'],
                ],
                'Description' => trim(($it->product?->sku ?? '') . ' ' . ($it->product?->name ?? '')),
            ];
        })->toArray();

        $payload = [
            'VendorRef'   => ['value' => $vendorId],
            'TxnDate'     => $po->sent_at?->toDateString() ?? now()->toDateString(),
            'DocNumber'   => $po->po_number,
            'CurrencyRef' => ['value' => $po->currency_code],
            'PrivateNote' => $po->notes,
            'Line'        => $lines,
        ];

        $row = QboSyncQueue::create([
            'entity_type' => 'purchase_order',
            'entity_id'   => $po->id,
            'operation'   => 'CREATE',
            'status'      => 'PENDING',
            'payload'     => $payload,
        ]);
        QboSyncJob::dispatch($row->id);
        return $row;
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
