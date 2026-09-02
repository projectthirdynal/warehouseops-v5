<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Models\SupplierInvoice;
use Illuminate\Support\Collection;
use Modules\Finance\Models\ThreeWayMatch;
use Modules\Procurement\Enums\GrnStatus;
use Modules\Procurement\Enums\PoStatus;
use Modules\Procurement\Models\PurchaseOrder;

class ThreeWayMatchService
{
    private const TOLERANCE = 0.01;

    /**
     * Run a three-way match for a PO + supplier invoice.
     * Compares: PO items ↔ GRN received items ↔ Supplier invoice items.
     */
    public function runMatch(int $poId, ?int $supplierInvoiceId = null, ?int $userId = null): ThreeWayMatch
    {
        $po = PurchaseOrder::with(['items', 'receivingReports.items.purchaseOrderItem'])->findOrFail($poId);
        $invoice = $supplierInvoiceId
            ? SupplierInvoice::with('items')->findOrFail($supplierInvoiceId)
            : null;

        $poTotal = (float) $po->total_amount;
        $grnTotal = $this->calculateGrnTotal($po);
        $invoiceTotal = $invoice ? (float) $invoice->total_amount : 0;

        $mismatches = [];
        $matchLevel = 'NONE';
        $status = 'PENDING';

        // Check 1: GRN must exist and be confirmed
        $confirmedGrns = $po->receivingReports->filter(fn ($grn) => $grn->status === GrnStatus::CONFIRMED);
        if ($confirmedGrns->isEmpty()) {
            $mismatches[] = [
                'type' => 'missing_grn',
                'message' => 'No confirmed GRN found for this PO.',
                'severity' => 'high',
            ];
        } else {
            // Check 2: PO quantity vs GRN received quantity (line-level)
            foreach ($po->items as $poItem) {
                $receivedQty = $confirmedGrns->sum(function ($grn) use ($poItem) {
                    return $grn->items->where('po_item_id', $poItem->id)
                        ->where('condition', 'GOOD')
                        ->sum('quantity_received');
                });

                if ($receivedQty < (int) $poItem->quantity_ordered) {
                    $mismatches[] = [
                        'type' => 'quantity_short',
                        'severity' => 'medium',
                        'po_item_id' => $poItem->id,
                        'description' => $poItem->product?->name ?? $poItem->supply?->name ?? "Item #{$poItem->id}",
                        'po_quantity' => (int) $poItem->quantity_ordered,
                        'grn_quantity' => $receivedQty,
                        'variance' => (int) $poItem->quantity_ordered - $receivedQty,
                        'message' => "Received {$receivedQty} of {$poItem->quantity_ordered} ordered.",
                    ];
                } elseif ($receivedQty > (int) $poItem->quantity_ordered) {
                    $mismatches[] = [
                        'type' => 'quantity_over',
                        'severity' => 'low',
                        'po_item_id' => $poItem->id,
                        'description' => $poItem->product?->name ?? $poItem->supply?->name ?? "Item #{$poItem->id}",
                        'po_quantity' => (int) $poItem->quantity_ordered,
                        'grn_quantity' => $receivedQty,
                        'variance' => $receivedQty - (int) $poItem->quantity_ordered,
                        'message' => "Received {$receivedQty} exceeds ordered {$poItem->quantity_ordered}.",
                    ];
                }
            }
            $matchLevel = 'HEADER';
        }

        // Check 3: If supplier invoice exists, compare totals
        if ($invoice) {
            $variance = $invoiceTotal - $poTotal;

            if (abs($variance) > self::TOLERANCE) {
                $mismatches[] = [
                    'type' => 'total_mismatch',
                    'severity' => abs($variance) / max($poTotal, 1) > 0.05 ? 'high' : 'medium',
                    'po_total' => $poTotal,
                    'invoice_total' => $invoiceTotal,
                    'variance' => round($variance, 2),
                    'message' => "Invoice total ({$invoiceTotal}) differs from PO total ({$poTotal}) by {$variance}.",
                ];
            }

            // Check 4: GRN total vs invoice total
            $grnInvoiceVariance = $invoiceTotal - $grnTotal;
            if (abs($grnInvoiceVariance) > self::TOLERANCE) {
                $mismatches[] = [
                    'type' => 'grn_invoice_mismatch',
                    'severity' => abs($grnInvoiceVariance) / max($grnTotal, 1) > 0.05 ? 'high' : 'medium',
                    'grn_total' => $grnTotal,
                    'invoice_total' => $invoiceTotal,
                    'variance' => round($grnInvoiceVariance, 2),
                    'message' => "Invoice total ({$invoiceTotal}) differs from GRN total ({$grnTotal}) by {$grnInvoiceVariance}.",
                ];
            }

            // Check 5: Line-level comparison if invoice has items
            if ($invoice->items->isNotEmpty()) {
                $lineMismatches = $this->compareLineItems($po, $confirmedGrns, $invoice);
                $mismatches = array_merge($mismatches, $lineMismatches);
                $matchLevel = 'LINE';
            }

            // Determine final status
            $highSeverity = array_filter($mismatches, fn ($m) => ($m['severity'] ?? 'low') === 'high');
            $status = empty($mismatches) ? 'MATCHED' : (empty($highSeverity) ? 'MISMATCH' : 'BLOCKED');
            if (empty($mismatches)) {
                $matchLevel = 'FULL';
            }
        }

        $varianceAmount = $invoice ? round($invoiceTotal - $poTotal, 2) : 0;

        return ThreeWayMatch::updateOrCreate(
            ['po_id' => $poId, 'supplier_invoice_id' => $supplierInvoiceId],
            [
                'status' => $status,
                'match_level' => $matchLevel,
                'mismatches' => $mismatches ?: null,
                'po_total' => $poTotal,
                'grn_total' => $grnTotal,
                'invoice_total' => $invoiceTotal,
                'variance_amount' => $varianceAmount,
                'matched_by' => $userId,
                'matched_at' => now(),
            ],
        );
    }

    /**
     * Calculate total value of confirmed GRN items for a PO.
     */
    private function calculateGrnTotal(PurchaseOrder $po): float
    {
        $confirmedGrns = $po->receivingReports->filter(fn ($grn) => $grn->status === GrnStatus::CONFIRMED);
        $total = 0;

        foreach ($confirmedGrns as $grn) {
            foreach ($grn->items as $grnItem) {
                if ($grnItem->condition === 'GOOD') {
                    $poItem = $grnItem->purchaseOrderItem;
                    if ($poItem) {
                        $total += (int) $grnItem->quantity_received * (float) $poItem->unit_price;
                    }
                }
            }
        }

        return round($total, 2);
    }

    /**
     * Line-level comparison: PO items ↔ GRN items ↔ Invoice items.
     */
    private function compareLineItems(PurchaseOrder $po, Collection $confirmedGrns, SupplierInvoice $invoice): array
    {
        $mismatches = [];

        foreach ($po->items as $poItem) {
            $receivedQty = $confirmedGrns->sum(function ($grn) use ($poItem) {
                return $grn->items->where('po_item_id', $poItem->id)
                    ->where('condition', 'GOOD')
                    ->sum('quantity_received');
            });

            $invoiceItem = $invoice->items->firstWhere('po_item_id', $poItem->id);

            if (! $invoiceItem) {
                // Try matching by product_id
                $invoiceItem = $invoice->items->firstWhere('product_id', $poItem->product_id);
            }

            if ($invoiceItem) {
                if ($invoiceItem->quantity != $receivedQty) {
                    $mismatches[] = [
                        'type' => 'line_quantity_mismatch',
                        'severity' => 'medium',
                        'po_item_id' => $poItem->id,
                        'description' => $poItem->product?->name ?? "Item #{$poItem->id}",
                        'grn_quantity' => $receivedQty,
                        'invoice_quantity' => $invoiceItem->quantity,
                        'variance' => $invoiceItem->quantity - $receivedQty,
                        'message' => "Invoice qty ({$invoiceItem->quantity}) ≠ GRN qty ({$receivedQty}).",
                    ];
                }

                $poUnitPrice = (float) $poItem->unit_price;
                $invoiceUnitPrice = (float) $invoiceItem->unit_price;
                if (abs($poUnitPrice - $invoiceUnitPrice) > self::TOLERANCE) {
                    $mismatches[] = [
                        'type' => 'line_price_mismatch',
                        'severity' => abs($poUnitPrice - $invoiceUnitPrice) / max($poUnitPrice, 1) > 0.05 ? 'high' : 'medium',
                        'po_item_id' => $poItem->id,
                        'description' => $poItem->product?->name ?? "Item #{$poItem->id}",
                        'po_unit_price' => $poUnitPrice,
                        'invoice_unit_price' => $invoiceUnitPrice,
                        'variance' => round($invoiceUnitPrice - $poUnitPrice, 4),
                        'message' => "Invoice price ({$invoiceUnitPrice}) ≠ PO price ({$poUnitPrice}).",
                    ];
                }
            } elseif ($receivedQty > 0) {
                $mismatches[] = [
                    'type' => 'missing_invoice_line',
                    'severity' => 'medium',
                    'po_item_id' => $poItem->id,
                    'description' => $poItem->product?->name ?? "Item #{$poItem->id}",
                    'message' => 'No matching invoice line found for this PO item.',
                ];
            }
        }

        // Check for invoice lines without matching PO items
        foreach ($invoice->items as $invItem) {
            $hasPoMatch = $po->items->contains(function ($poItem) use ($invItem) {
                return ($invItem->po_item_id && $invItem->po_item_id === $poItem->id)
                    || ($invItem->product_id && $invItem->product_id === $poItem->product_id);
            });

            if (! $hasPoMatch) {
                $mismatches[] = [
                    'type' => 'extra_invoice_line',
                    'severity' => 'low',
                    'invoice_item_id' => $invItem->id,
                    'description' => $invItem->description ?? "Invoice item #{$invItem->id}",
                    'message' => 'Invoice line has no corresponding PO item.',
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Get dashboard data with filtering.
     */
    public function getDashboardData(array $filters = []): array
    {
        $query = ThreeWayMatch::with([
            'purchaseOrder:id,po_number,supplier_id,status,total_amount',
            'purchaseOrder.supplier:id,name',
            'supplierInvoice:id,ref,total_amount,status',
            'matcher:id,name',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $matches = $query->orderByDesc('updated_at')->limit(100)->get();

        return [
            'matches' => $matches->map(fn ($m) => $this->formatMatchSummary($m)),
            'stats' => $this->getStats(),
            'filters' => $filters,
        ];
    }

    /**
     * Get detailed match data for a single match record.
     */
    public function getMatchDetail(int $matchId): array
    {
        $match = ThreeWayMatch::with([
            'purchaseOrder.items.product',
            'purchaseOrder.items.supply',
            'purchaseOrder.receivingReports.items.purchaseOrderItem',
            'purchaseOrder.supplier',
            'supplierInvoice.items',
            'matcher',
        ])->findOrFail($matchId);

        $po = $match->purchaseOrder;
        $grns = $po->receivingReports->filter(fn ($g) => $g->status === GrnStatus::CONFIRMED);

        $lineComparison = $po->items->map(function ($poItem) use ($grns, $match) {
            $receivedQty = $grns->sum(function ($grn) use ($poItem) {
                return $grn->items->where('po_item_id', $poItem->id)
                    ->where('condition', 'GOOD')
                    ->sum('quantity_received');
            });

            $rejectedQty = $grns->sum(function ($grn) use ($poItem) {
                return $grn->items->where('po_item_id', $poItem->id)
                    ->sum('quantity_rejected');
            });

            $invoiceItem = $match->supplierInvoice?->items->firstWhere('po_item_id', $poItem->id)
                ?? $match->supplierInvoice?->items->firstWhere('product_id', $poItem->product_id);

            return [
                'po_item_id' => $poItem->id,
                'description' => $poItem->product?->name ?? $poItem->supply?->name ?? "Item #{$poItem->id}",
                'sku' => $poItem->product?->sku,
                'po_quantity' => (int) $poItem->quantity_ordered,
                'po_unit_price' => (float) $poItem->unit_price,
                'po_line_total' => (float) $poItem->line_total,
                'grn_quantity' => $receivedQty,
                'rejected_quantity' => $rejectedQty,
                'grn_line_total' => round($receivedQty * (float) $poItem->unit_price, 2),
                'invoice_quantity' => $invoiceItem?->quantity,
                'invoice_unit_price' => $invoiceItem?->unit_price,
                'invoice_line_total' => $invoiceItem?->line_total,
                'qty_variance' => $invoiceItem ? $invoiceItem->quantity - $receivedQty : null,
                'price_variance' => $invoiceItem ? round((float) $invoiceItem->unit_price - (float) $poItem->unit_price, 4) : null,
            ];
        });

        return [
            'match' => $this->formatMatchSummary($match),
            'po' => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'status' => $po->status->value,
                'supplier' => $po->supplier?->name,
                'total_amount' => (float) $po->total_amount,
                'subtotal' => (float) $po->subtotal,
                'tax_amount' => (float) $po->tax_amount,
            ],
            'grns' => $grns->map(fn ($g) => [
                'id' => $g->id,
                'grn_number' => $g->grn_number,
                'received_at' => $g->received_at?->toDateTimeString(),
                'status' => $g->status->value,
            ]),
            'supplier_invoice' => $match->supplierInvoice ? [
                'id' => $match->supplierInvoice->id,
                'ref' => $match->supplierInvoice->ref,
                'status' => $match->supplierInvoice->status,
                'total_amount' => (float) $match->supplierInvoice->total_amount,
            ] : null,
            'line_comparison' => $lineComparison,
            'mismatches' => $match->mismatches ?? [],
        ];
    }

    /**
     * Get aggregate stats.
     */
    public function getStats(): array
    {
        $total = ThreeWayMatch::count();
        $matched = ThreeWayMatch::where('status', 'MATCHED')->count();
        $mismatch = ThreeWayMatch::where('status', 'MISMATCH')->count();
        $blocked = ThreeWayMatch::where('status', 'BLOCKED')->count();
        $pending = ThreeWayMatch::where('status', 'PENDING')->count();

        $totalVariance = (float) ThreeWayMatch::sum('variance_amount');

        return [
            'total' => $total,
            'matched' => $matched,
            'mismatch' => $mismatch,
            'blocked' => $blocked,
            'pending' => $pending,
            'match_rate' => $total > 0 ? round($matched / $total * 100, 1) : 0,
            'total_variance' => round($totalVariance, 2),
        ];
    }

    /**
     * Get list of POs eligible for three-way matching (SENT or received, with supplier invoices).
     */
    public function getEligiblePos(): Collection
    {
        return PurchaseOrder::with(['supplier:id,name', 'items'])
            ->whereIn('status', [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED, PoStatus::RECEIVED])
            ->whereDoesntHave('threeWayMatches')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'supplier' => $po->supplier?->name,
                'status' => $po->status->value,
                'total_amount' => (float) $po->total_amount,
                'items_count' => $po->items->count(),
            ]);
    }

    private function formatMatchSummary(ThreeWayMatch $m): array
    {
        return [
            'id' => $m->id,
            'po_id' => $m->po_id,
            'po_number' => $m->purchaseOrder?->po_number,
            'supplier' => $m->purchaseOrder?->supplier?->name,
            'po_status' => $m->purchaseOrder?->status?->value,
            'supplier_invoice_id' => $m->supplier_invoice_id,
            'supplier_invoice_ref' => $m->supplierInvoice?->ref,
            'invoice_status' => $m->supplierInvoice?->status,
            'status' => $m->status,
            'match_level' => $m->match_level,
            'po_total' => (float) $m->po_total,
            'grn_total' => (float) $m->grn_total,
            'invoice_total' => (float) $m->invoice_total,
            'variance_amount' => (float) $m->variance_amount,
            'mismatch_count' => $m->mismatches ? count($m->mismatches) : 0,
            'mismatches' => $m->mismatches,
            'matched_by' => $m->matcher?->name,
            'matched_at' => $m->matched_at?->toDateTimeString(),
            'updated_at' => $m->updated_at?->toDateTimeString(),
        ];
    }
}
