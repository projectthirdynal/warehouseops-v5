<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\InsufficientCostLotsException;
use App\Domain\Finance\Models\CogsEntry;
use App\Domain\Finance\Models\FinanceSetting;
use App\Domain\Inventory\Models\StockCostLot;
use App\Domain\Product\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records cost of goods sold by walking FIFO cost lots.
 * On first call ever, locks the cogs_method finance_setting.
 */
class CogsService
{
    /**
     * Record COGS for a sold quantity. Returns the CogsEntry rows created (one per lot consumed).
     *
     * @return Collection<int, CogsEntry>
     */
    public function record(
        int $productId,
        ?int $variantId,
        float $quantity,
        ?int $waybillId = null,
        ?int $orderId = null,
        ?int $userId = null,
    ): Collection {
        if ($quantity <= 0) {
            return collect();
        }

        $methodSetting = FinanceSetting::getValue('cogs_method') ?? ['method' => 'FIFO'];
        $method        = $methodSetting['method'] ?? 'FIFO';

        return DB::transaction(function () use ($productId, $variantId, $quantity, $waybillId, $orderId, $userId, $method) {
            // Lock cogs_method on first record
            if (! FinanceSetting::isLocked('cogs_method')) {
                FinanceSetting::lock(
                    'cogs_method',
                    $userId ?? 0,
                    "first cogs entry product={$productId} waybill=" . ($waybillId ?? 'null')
                );
            }

            // Use FEFO (earliest expiry first) when product has expiry tracking, otherwise pure FIFO by received_at
            $product = Product::find($productId);
            $useFefo = (bool) ($product?->expiry_tracking ?? false);

            $lotsQuery = StockCostLot::where('product_id', $productId)
                ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
                ->where('quantity_remaining', '>', 0);

            if ($useFefo) {
                $lotsQuery->orderByRaw('expiry_date IS NULL, expiry_date ASC, received_at ASC');
            } else {
                $lotsQuery->orderBy('received_at', 'asc')->orderBy('id', 'asc');
            }

            $lots = $lotsQuery->lockForUpdate()->get();

            $remaining = $quantity;
            $entries   = collect();

            foreach ($lots as $lot) {
                if ($remaining <= 0) break;

                $consume = min((float) $lot->quantity_remaining, $remaining);
                if ($consume <= 0) continue;

                $lot->quantity_remaining = (float) $lot->quantity_remaining - $consume;
                $lot->save();

                $entry = CogsEntry::create([
                    'product_id'    => $productId,
                    'variant_id'    => $variantId,
                    'waybill_id'    => $waybillId,
                    'order_id'      => $orderId,
                    'cost_lot_id'   => $lot->id,
                    'method'        => $method,
                    'quantity'      => $consume,
                    'unit_cost'     => $lot->unit_cost,
                    'total_cost'    => round($consume * (float) $lot->unit_cost, 4),
                    'currency_code' => $lot->currency_code,
                    'exchange_rate' => $lot->exchange_rate,
                    'recorded_at'   => now(),
                ]);

                $entries->push($entry);
                $remaining -= $consume;
            }

            if ($remaining > 0.0001) {
                throw new InsufficientCostLotsException($productId, $quantity, $quantity - $remaining);
            }

            return $entries;
        });
    }

    /**
     * Reverse COGS for a returned waybill — adds the consumed quantities back to the lots.
     */
    public function reverse(int $waybillId): void
    {
        DB::transaction(function () use ($waybillId) {
            $entries = CogsEntry::where('waybill_id', $waybillId)->lockForUpdate()->get();
            foreach ($entries as $entry) {
                if ($entry->cost_lot_id) {
                    StockCostLot::where('id', $entry->cost_lot_id)
                        ->update(['quantity_remaining' => DB::raw("quantity_remaining + {$entry->quantity}")]);
                }
            }
            CogsEntry::where('waybill_id', $waybillId)->delete();
        });
    }
}
