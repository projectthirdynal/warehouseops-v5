<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\CodReconciliationItem;
use App\Domain\Finance\Models\CodSettlement;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Domain\Waybill\Models\Waybill;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CodReconciliationService
{
    /**
     * Auto-match a settlement against delivered orders/waybills by courier and date range.
     */
    public function autoMatch(CodSettlement $settlement): array
    {
        if (! $settlement->isReconcilable()) {
            throw new \DomainException('Settlement must be in RECEIVED status to reconcile.');
        }

        $periodStart = Carbon::parse($settlement->period_start)->startOfDay();
        $periodEnd = Carbon::parse($settlement->period_end)->endOfDay();

        // Find delivered orders matching courier and period
        $orders = Order::where('courier_code', $settlement->courier_code)
            ->where('status', OrderStatus::DELIVERED)
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$periodStart, $periodEnd])
            ->where('cod_amount', '>', 0)
            ->orderBy('delivered_at')
            ->get();

        // Find delivered waybills matching courier and period
        $waybills = Waybill::where('courier_provider', $settlement->courier_code)
            ->where('status', WaybillStatus::DELIVERED)
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$periodStart, $periodEnd])
            ->where('amount', '>', 0)
            ->orderBy('delivered_at')
            ->get();

        return DB::transaction(function () use ($settlement, $orders, $waybills) {
            // Clear existing reconciliation items for this settlement
            $settlement->reconciliationItems()->delete();

            $matchedCount = 0;
            $unmatchedCount = 0;
            $expectedCod = 0.0;
            $items = [];

            // Match orders first (primary source of COD)
            foreach ($orders as $order) {
                $codAmount = (float) $order->cod_amount;
                $expectedCod += $codAmount;

                // Try to find a matching waybill
                $waybill = $order->waybill_id
                    ? $waybills->firstWhere('id', $order->waybill_id)
                    : null;

                $item = [
                    'cod_settlement_id' => $settlement->id,
                    'order_id' => $order->id,
                    'waybill_id' => $waybill?->id,
                    'courier_code' => $settlement->courier_code,
                    'order_number' => $order->order_number,
                    'waybill_number' => $waybill?->waybill_number,
                    'expected_cod' => $codAmount,
                    'remitted_cod' => 0,
                    'variance' => -$codAmount,
                    'match_status' => CodReconciliationItem::MATCH_STATUS_UNMATCHED,
                    'match_type' => null,
                    'matched_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $items[] = $item;
                $unmatchedCount++;
            }

            // Add waybills that don't have linked orders
            $linkedWaybillIds = $orders->pluck('waybill_id')->filter()->toArray();
            $orphanWaybills = $waybills->whereNotIn('id', $linkedWaybillIds);

            foreach ($orphanWaybills as $waybill) {
                $codAmount = (float) $waybill->amount;
                $expectedCod += $codAmount;

                $items[] = [
                    'cod_settlement_id' => $settlement->id,
                    'order_id' => null,
                    'waybill_id' => $waybill->id,
                    'courier_code' => $settlement->courier_code,
                    'order_number' => null,
                    'waybill_number' => $waybill->waybill_number,
                    'expected_cod' => $codAmount,
                    'remitted_cod' => 0,
                    'variance' => -$codAmount,
                    'match_status' => CodReconciliationItem::MATCH_STATUS_UNMATCHED,
                    'match_type' => null,
                    'matched_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $unmatchedCount++;
            }

            // Bulk insert items
            if (! empty($items)) {
                CodReconciliationItem::insert($items);
            }

            // Auto-match: distribute total_cod_collected across items proportionally
            $totalCollected = (float) $settlement->total_cod_collected;
            if ($totalCollected > 0 && $expectedCod > 0) {
                $this->distributeRemittance($settlement, $totalCollected, $expectedCod);
            }

            // Recount after distribution
            $settlement->refresh();
            $matchedCount = $settlement->reconciliationItems()
                ->whereIn('match_status', [
                    CodReconciliationItem::MATCH_STATUS_MATCHED,
                    CodReconciliationItem::MATCH_STATUS_MANUAL_MATCH,
                ])->count();
            $unmatchedCount = $settlement->reconciliationItems()
                ->where('match_status', CodReconciliationItem::MATCH_STATUS_UNMATCHED)->count();

            $variance = $totalCollected - $expectedCod;

            $settlement->update([
                'expected_cod' => $expectedCod,
                'variance' => $variance,
                'matched_count' => $matchedCount,
                'unmatched_count' => $unmatchedCount,
            ]);

            Log::info('COD reconciliation auto-match completed', [
                'settlement_id' => $settlement->id,
                'orders' => $orders->count(),
                'waybills' => $waybills->count(),
                'matched' => $matchedCount,
                'unmatched' => $unmatchedCount,
                'expected_cod' => $expectedCod,
                'variance' => $variance,
            ]);

            return [
                'orders_found' => $orders->count(),
                'waybills_found' => $waybills->count(),
                'matched' => $matchedCount,
                'unmatched' => $unmatchedCount,
                'expected_cod' => $expectedCod,
                'variance' => $variance,
            ];
        });
    }

    /**
     * Distribute the remitted COD amount across items proportionally.
     */
    private function distributeRemittance(CodSettlement $settlement, float $totalCollected, float $expectedCod): void
    {
        $items = $settlement->reconciliationItems()->orderBy('expected_cod', 'desc')->get();
        $allocated = 0.0;

        foreach ($items as $index => $item) {
            if ($index === $items->count() - 1) {
                // Last item gets the remainder to avoid rounding drift
                $remitted = round($totalCollected - $allocated, 2);
            } else {
                $proportion = (float) $item->expected_cod / $expectedCod;
                $remitted = round($totalCollected * $proportion, 2);
                $allocated += $remitted;
            }

            $variance = round($remitted - (float) $item->expected_cod, 2);
            $status = abs($variance) < 0.01
                ? CodReconciliationItem::MATCH_STATUS_MATCHED
                : ($variance != 0 ? CodReconciliationItem::MATCH_STATUS_MISMATCH : CodReconciliationItem::MATCH_STATUS_MATCHED);

            $item->update([
                'remitted_cod' => $remitted,
                'variance' => $variance,
                'match_status' => $status,
                'match_type' => CodReconciliationItem::MATCH_TYPE_AUTO,
                'matched_at' => now(),
            ]);
        }
    }

    /**
     * Manually match a reconciliation item to an order.
     */
    public function manualMatch(int $itemId, int $orderId, ?float $remittedCod = null): CodReconciliationItem
    {
        $item = CodReconciliationItem::findOrFail($itemId);
        $order = Order::findOrFail($orderId);

        $remittedCod = $remittedCod ?? (float) $order->cod_amount;
        $variance = round($remittedCod - (float) $item->expected_cod, 2);

        $item->update([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'remitted_cod' => $remittedCod,
            'variance' => $variance,
            'match_status' => CodReconciliationItem::MATCH_STATUS_MANUAL_MATCH,
            'match_type' => CodReconciliationItem::MATCH_TYPE_MANUAL,
            'matched_at' => now(),
        ]);

        $this->updateSettlementSummary($item->cod_settlement_id);

        return $item->fresh();
    }

    /**
     * Unmatch a reconciliation item.
     */
    public function unmatch(int $itemId): CodReconciliationItem
    {
        $item = CodReconciliationItem::findOrFail($itemId);

        $item->update([
            'order_id' => null,
            'order_number' => null,
            'remitted_cod' => 0,
            'variance' => -(float) $item->expected_cod,
            'match_status' => CodReconciliationItem::MATCH_STATUS_UNMATCHED,
            'match_type' => null,
            'matched_at' => null,
        ]);

        $this->updateSettlementSummary($item->cod_settlement_id);

        return $item->fresh();
    }

    /**
     * Finalize reconciliation — mark settlement as RECONCILED and create financial transactions.
     */
    public function finalize(CodSettlement $settlement, int $userId): CodSettlement
    {
        if (! $settlement->isReconcilable()) {
            throw new \DomainException('Settlement must be in RECEIVED status to finalize.');
        }

        $unmatched = $settlement->reconciliationItems()->unmatched()->count();
        if ($unmatched > 0) {
            throw new \DomainException("Cannot finalize: {$unmatched} item(s) still unmatched.");
        }

        return DB::transaction(function () use ($settlement, $userId) {
            $settlement->update([
                'status' => 'RECONCILED',
                'reconciled_at' => now(),
                'reconciled_by' => $userId,
            ]);

            // Create financial transaction for COD collection
            $refNum = $settlement->reference_number ?? 'N/A';
            DB::table('financial_transactions')->insert([
                'type' => 'COD_COLLECTION',
                'amount' => (float) $settlement->total_cod_collected,
                'reference_type' => CodSettlement::class,
                'reference_id' => $settlement->id,
                'description' => "COD Settlement #{$settlement->id} - {$settlement->courier_code} ({$refNum})",
                'recorded_by' => $userId,
                'transaction_date' => $settlement->reconciled_at->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create financial transaction for courier fee
            if ((float) $settlement->courier_fee > 0) {
                DB::table('financial_transactions')->insert([
                    'type' => 'SHIPPING_COST',
                    'amount' => -(float) $settlement->courier_fee,
                    'reference_type' => CodSettlement::class,
                    'reference_id' => $settlement->id,
                    'description' => "Courier Fee for Settlement #{$settlement->id} - {$settlement->courier_code}",
                    'recorded_by' => $userId,
                    'transaction_date' => $settlement->reconciled_at->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create variance adjustment if variance is non-zero
            if (abs((float) $settlement->variance) > 0.01) {
                DB::table('financial_transactions')->insert([
                    'type' => 'ADJUSTMENT',
                    'amount' => (float) $settlement->variance,
                    'reference_type' => CodSettlement::class,
                    'reference_id' => $settlement->id,
                    'description' => "COD Variance for Settlement #{$settlement->id} - {$settlement->courier_code}",
                    'recorded_by' => $userId,
                    'transaction_date' => $settlement->reconciled_at->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Log::info('COD settlement reconciled', [
                'settlement_id' => $settlement->id,
                'reconciled_by' => $userId,
                'variance' => (float) $settlement->variance,
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * Get reconciliation stats for dashboard.
     */
    public function getStats(): array
    {
        $totalExpected = (float) CodSettlement::whereNotIn('status', ['PENDING'])->sum('expected_cod');
        $totalCollected = (float) CodSettlement::sum('total_cod_collected');
        $totalVariance = (float) CodSettlement::where('status', 'RECONCILED')->sum('variance');

        $pendingCount = CodSettlement::where('status', 'RECEIVED')->count();
        $reconciledCount = CodSettlement::where('status', 'RECONCILED')->count();

        $unmatchedItems = CodReconciliationItem::unmatched()->count();
        $mismatchItems = CodReconciliationItem::mismatch()->count();

        return [
            'total_expected' => $totalExpected,
            'total_collected' => $totalCollected,
            'total_variance' => $totalVariance,
            'pending_reconciliation' => $pendingCount,
            'reconciled_count' => $reconciledCount,
            'unmatched_items' => $unmatchedItems,
            'mismatch_items' => $mismatchItems,
        ];
    }

    /**
     * Get unmatched orders for a settlement (candidates for manual matching).
     */
    public function getUnmatchedOrders(CodSettlement $settlement, int $limit = 50): Collection
    {
        $periodStart = Carbon::parse($settlement->period_start)->startOfDay();
        $periodEnd = Carbon::parse($settlement->period_end)->endOfDay();

        // Already matched order IDs
        $matchedOrderIds = $settlement->reconciliationItems()
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->toArray();

        return Order::where('courier_code', $settlement->courier_code)
            ->where('status', OrderStatus::DELIVERED)
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$periodStart, $periodEnd])
            ->where('cod_amount', '>', 0)
            ->whereNotIn('id', $matchedOrderIds)
            ->limit($limit)
            ->get();
    }

    /**
     * Update settlement summary counts after a manual match/unmatch.
     */
    private function updateSettlementSummary(int $settlementId): void
    {
        $settlement = CodSettlement::find($settlementId);
        if (! $settlement) {
            return;
        }

        $matchedCount = $settlement->reconciliationItems()
            ->whereIn('match_status', [
                CodReconciliationItem::MATCH_STATUS_MATCHED,
                CodReconciliationItem::MATCH_STATUS_MANUAL_MATCH,
            ])->count();

        $unmatchedCount = $settlement->reconciliationItems()
            ->where('match_status', CodReconciliationItem::MATCH_STATUS_UNMATCHED)->count();

        $expectedCod = (float) $settlement->reconciliationItems()->sum('expected_cod');
        $remittedCod = (float) $settlement->reconciliationItems()->sum('remitted_cod');
        $variance = round($remittedCod - $expectedCod, 2);

        $settlement->update([
            'matched_count' => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'expected_cod' => $expectedCod,
            'variance' => $variance,
        ]);
    }
}
