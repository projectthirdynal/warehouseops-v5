<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Services;

use App\Domain\Inventory\Services\StockService;
use App\Domain\Procurement\Enums\GrnStatus;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\ReceivingReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcurementService
{
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    /**
     * Submit PR for approval. Auto-approves if estimated total ≤ threshold.
     */
    public function submitPr(PurchaseRequest $pr): void
    {
        if ($pr->status !== PrStatus::DRAFT) {
            throw new RuntimeException("PR must be DRAFT to submit, currently {$pr->status->value}.");
        }

        $threshold = (float) (DB::table('finance_settings')
            ->where('key', 'pr_auto_approve_under')
            ->value('value') ? json_decode((string) DB::table('finance_settings')
            ->where('key', 'pr_auto_approve_under')->value('value'), true)['amount'] ?? 0 : 0);

        $pr->status = PrStatus::SUBMITTED;
        if ((float) $pr->estimated_total <= $threshold && $threshold > 0) {
            $pr->status      = PrStatus::APPROVED;
            $pr->approved_at = now();
        }
        $pr->save();
    }

    public function approvePr(PurchaseRequest $pr, User $approver): void
    {
        if ($pr->status !== PrStatus::SUBMITTED) {
            throw new RuntimeException("PR must be SUBMITTED to approve.");
        }
        $pr->status      = PrStatus::APPROVED;
        $pr->approved_by = $approver->id;
        $pr->approved_at = now();
        $pr->save();
    }

    public function rejectPr(PurchaseRequest $pr, User $approver, string $reason): void
    {
        if ($pr->status !== PrStatus::SUBMITTED) {
            throw new RuntimeException("PR must be SUBMITTED to reject.");
        }
        $pr->status          = PrStatus::REJECTED;
        $pr->approved_by     = $approver->id;
        $pr->approved_at     = now();
        $pr->rejected_reason = $reason;
        $pr->save();
    }

    /**
     * Send PO to supplier — locks pricing, marks SENT.
     */
    public function sendPo(PurchaseOrder $po): void
    {
        if ($po->status !== PoStatus::DRAFT) {
            throw new RuntimeException("PO must be DRAFT to send.");
        }
        $po->recalculateTotals();
        $po->status  = PoStatus::SENT;
        $po->sent_at = now();
        $po->save();

        if ($po->purchaseRequest && $po->purchaseRequest->status === PrStatus::APPROVED) {
            $po->purchaseRequest->update(['status' => PrStatus::CONVERTED]);
        }
    }

    public function cancelPo(PurchaseOrder $po, string $reason): void
    {
        if ($po->status === PoStatus::RECEIVED) {
            throw new RuntimeException("Cannot cancel a fully received PO.");
        }
        $po->status = PoStatus::CANCELLED;
        $po->notes  = trim(($po->notes ?? '') . "\nCANCELLED: " . $reason);
        $po->save();
    }

    /**
     * Confirm a GRN — fires StockService::stockIn() per line, updates PO line received quantities,
     * advances PO state to PARTIALLY_RECEIVED or RECEIVED.
     */
    public function confirmGrn(ReceivingReport $grn): void
    {
        if ($grn->status !== GrnStatus::DRAFT) {
            throw new RuntimeException("GRN must be DRAFT to confirm.");
        }

        DB::transaction(function () use ($grn) {
            $grn->load(['items.purchaseOrderItem', 'purchaseOrder.items']);

            foreach ($grn->items as $grnItem) {
                $poItem = $grnItem->purchaseOrderItem;

                if ($grnItem->quantity_received > 0 && $grnItem->condition === 'GOOD' && $poItem->product_id) {
                    $this->stockService->stockIn(
                        productId:    (int) $poItem->product_id,
                        variantId:    $poItem->variant_id ? (int) $poItem->variant_id : null,
                        warehouseId:  (int) $grn->warehouse_id,
                        locationId:   $grn->location_id ? (int) $grn->location_id : null,
                        quantity:     (int) $grnItem->quantity_received,
                        unitCost:     (float) $poItem->unit_price,
                        grnItemId:    (int) $grnItem->id,
                        batchNumber:  $grnItem->batch_number,
                        expiryDate:   $grnItem->expiry_date?->toDateString(),
                        performedBy:  (int) $grn->received_by,
                        currencyCode: $grn->purchaseOrder->currency_code,
                        exchangeRate: (float) $grn->exchange_rate,
                    );
                }

                $poItem->quantity_received += (int) $grnItem->quantity_received;
                $poItem->save();
            }

            $grn->status       = GrnStatus::CONFIRMED;
            $grn->confirmed_at = now();
            $grn->save();

            $po = $grn->purchaseOrder->fresh('items');
            $totalOrdered  = (int) $po->items->sum('quantity_ordered');
            $totalReceived = (int) $po->items->sum('quantity_received');

            $po->status = match (true) {
                $totalReceived <= 0                  => $po->status,
                $totalReceived >= $totalOrdered      => PoStatus::RECEIVED,
                default                              => PoStatus::PARTIALLY_RECEIVED,
            };
            $po->save();
        });
    }
}
