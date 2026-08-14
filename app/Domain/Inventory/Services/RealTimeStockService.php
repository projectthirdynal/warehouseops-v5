<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockAlert;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Support\Facades\DB;

class RealTimeStockService
{
    /**
     * Aggregate real-time stock metrics and low-stock alerts.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getDashboardData(array $filters = []): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        return [
            'summary' => $this->getSummary($warehouseId),
            'warehouse_breakdown' => $this->getWarehouseBreakdown($warehouseId),
            'low_stock_alerts' => $this->getLowStockAlerts($warehouseId, $filters['alert_type'] ?? null),
            'recent_movements' => $this->getRecentMovements($warehouseId),
            'top_movers' => $this->getTopMovers($warehouseId),
            'reorder_triggers' => $this->getReorderTriggers($warehouseId),
            'filters' => [
                'warehouse_id' => $warehouseId,
                'alert_type' => $filters['alert_type'] ?? null,
            ],
        ];
    }

    /**
     * Recalculate low-stock / out-of-stock / overstock alerts and persist them.
     *
     * @return array{created: int, resolved: int}
     */
    public function syncAlerts(): array
    {
        $created = 0;
        $resolved = 0;

        // Mark all open alerts stale; re-verify and resolve those no longer applicable.
        StockAlert::where('status', StockAlert::STATUS_OPEN)
            ->orWhere('status', StockAlert::STATUS_ACKNOWLEDGED)
            ->chunkById(100, function ($alerts) use (&$resolved) {
                foreach ($alerts as $alert) {
                    if (! $this->alertStillValid($alert)) {
                        $alert->update(['status' => StockAlert::STATUS_RESOLVED]);
                        $resolved++;
                    }
                }
            });

        // Product stocks
        ProductStock::with(['product', 'warehouse'])
            ->when(true, function ($q) {
                return $q->whereHas('product', fn ($pq) => $pq->where('is_active', true));
            })
            ->chunk(100, function ($stocks) use (&$created) {
                foreach ($stocks as $stock) {
                    if ($this->ensureAlert($stock, 'App\Domain\Product\Models\ProductStock')) {
                        $created++;
                    }
                }
            });

        // Supply stocks
        SupplyStock::with(['supply', 'warehouse'])
            ->when(true, function ($q) {
                return $q->whereHas('supply', fn ($sq) => $sq->where('is_active', true)->whereNull('deleted_at'));
            })
            ->chunk(100, function ($stocks) use (&$created) {
                foreach ($stocks as $stock) {
                    if ($this->ensureAlert($stock, 'App\Domain\Inventory\Models\SupplyStock')) {
                        $created++;
                    }
                }
            });

        return ['created' => $created, 'resolved' => $resolved];
    }

    /**
     * Acknowledge an open stock alert.
     */
    public function acknowledgeAlert(StockAlert $alert, int $userId, ?string $notes = null): StockAlert
    {
        $alert->update([
            'status' => StockAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'notes' => $notes,
        ]);

        return $alert;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSummary(?int $warehouseId): array
    {
        $productStockValue = $this->productStockValue($warehouseId);
        $supplyStockValue = $this->supplyStockValue($warehouseId);

        $productLowStock = $this->countLowStock(ProductStock::query(), $warehouseId, 'product');
        $supplyLowStock = $this->countLowStock(SupplyStock::query(), $warehouseId, 'supply');

        $productOutOfStock = $this->countOutOfStock(ProductStock::query(), $warehouseId, 'product');
        $supplyOutOfStock = $this->countOutOfStock(SupplyStock::query(), $warehouseId, 'supply');

        $totalProductSkus = Product::where('is_active', true)->count();
        $totalSupplies = Supply::where('is_active', true)->whereNull('deleted_at')->count();

        return [
            'total_sku_count' => $totalProductSkus + $totalSupplies,
            'product_stock_value' => $productStockValue,
            'supply_stock_value' => $supplyStockValue,
            'total_stock_value' => $productStockValue + $supplyStockValue,
            'low_stock_count' => $productLowStock + $supplyLowStock,
            'out_of_stock_count' => $productOutOfStock + $supplyOutOfStock,
            'open_alert_count' => StockAlert::where('status', StockAlert::STATUS_OPEN)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->count(),
            'warehouse_id' => $warehouseId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getWarehouseBreakdown(?int $warehouseId): array
    {
        return Warehouse::where('is_active', true)
            ->when($warehouseId, fn ($q) => $q->where('id', $warehouseId))
            ->get()
            ->map(function (Warehouse $warehouse) {
                $productValue = $this->productStockValue($warehouse->id);
                $supplyValue = $this->supplyStockValue($warehouse->id);
                $productLow = $this->countLowStock(ProductStock::query(), $warehouse->id, 'product');
                $supplyLow = $this->countLowStock(SupplyStock::query(), $warehouse->id, 'supply');

                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'code' => $warehouse->code,
                    'stock_value' => $productValue + $supplyValue,
                    'low_stock_count' => $productLow + $supplyLow,
                    'product_value' => $productValue,
                    'supply_value' => $supplyValue,
                ];
            })
            ->sortByDesc('stock_value')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getLowStockAlerts(?int $warehouseId, ?string $alertType = null): array
    {
        return StockAlert::with(['stockable', 'warehouse'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($alertType, fn ($q) => $q->where('alert_type', $alertType))
            ->whereIn('status', [StockAlert::STATUS_OPEN, StockAlert::STATUS_ACKNOWLEDGED])
            ->latest()
            ->limit(50)
            ->get()
            ->map(function (StockAlert $alert) {
                $stockable = $alert->stockable;
                $name = method_exists($stockable, 'product')
                    ? $stockable->product?->name
                    : ($stockable->supply?->name ?? '');
                $sku = method_exists($stockable, 'product')
                    ? $stockable->product?->sku
                    : ($stockable->supply?->sku ?? '');

                return [
                    'id' => $alert->id,
                    'alert_type' => $alert->alert_type,
                    'status' => $alert->status,
                    'warehouse' => $alert->warehouse?->name ?? 'Default',
                    'item_name' => $name ?? 'Unknown',
                    'item_sku' => $sku ?? '—',
                    'current_stock' => $alert->current_stock,
                    'reserved_stock' => $alert->reserved_stock,
                    'reorder_point' => $alert->reorder_point,
                    'suggested_reorder_qty' => $alert->suggested_reorder_qty,
                    'created_at' => $alert->created_at->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentMovements(?int $warehouseId): array
    {
        $productMovements = DB::table('inventory_movements as im')
            ->leftJoin('products as p', 'p.id', '=', 'im.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'im.warehouse_id')
            ->when($warehouseId, fn ($q) => $q->where('im.warehouse_id', $warehouseId))
            ->select([
                'im.id', 'im.type', 'im.quantity', 'im.created_at',
                'p.name as item_name', 'p.sku as item_sku',
                'w.name as warehouse_name',
                DB::raw("'product' as stream"),
            ])
            ->latest('im.created_at')
            ->limit(20)
            ->get()
            ->toArray();

        $supplyMovements = DB::table('supply_movements as sm')
            ->leftJoin('supplies as s', 's.id', '=', 'sm.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->select([
                'sm.id', 'sm.type', 'sm.quantity', 'sm.created_at',
                's.name as item_name', 's.sku as item_sku',
                'w.name as warehouse_name',
                DB::raw("'supply' as stream"),
            ])
            ->latest('sm.created_at')
            ->limit(20)
            ->get()
            ->toArray();

        return collect($productMovements)
            ->merge($supplyMovements)
            ->sortByDesc('created_at')
            ->take(20)
            ->values()
            ->map(fn ($m) => [
                'id' => $m->id,
                'stream' => $m->stream,
                'type' => $m->type,
                'quantity' => (int) $m->quantity,
                'item_name' => $m->item_name ?? 'Unknown',
                'item_sku' => $m->item_sku ?? '—',
                'warehouse_name' => $m->warehouse_name ?? 'Default',
                'created_at' => $m->created_at,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTopMovers(?int $warehouseId): array
    {
        $productMovers = DB::table('inventory_movements as im')
            ->join('products as p', 'p.id', '=', 'im.product_id')
            ->when($warehouseId, fn ($q) => $q->where('im.warehouse_id', $warehouseId))
            ->where('im.created_at', '>=', now()->subDays(30))
            ->select('p.id', 'p.sku', 'p.name', DB::raw('SUM(ABS(im.quantity)) as total_qty'))
            ->groupBy('p.id', 'p.sku', 'p.name')
            ->orderByRaw('total_qty DESC')
            ->limit(10)
            ->get();

        $supplyMovers = DB::table('supply_movements as sm')
            ->join('supplies as s', 's.id', '=', 'sm.supply_id')
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->where('sm.created_at', '>=', now()->subDays(30))
            ->select('s.id', 's.sku', 's.name', DB::raw('SUM(ABS(sm.quantity)) as total_qty'))
            ->groupBy('s.id', 's.sku', 's.name')
            ->orderByRaw('total_qty DESC')
            ->limit(10)
            ->get();

        return collect($productMovers)
            ->merge($supplyMovers)
            ->sortByDesc('total_qty')
            ->take(10)
            ->values()
            ->map(fn ($m) => [
                'id' => $m->id,
                'sku' => $m->sku,
                'name' => $m->name,
                'total_qty' => (int) $m->total_qty,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getReorderTriggers(?int $warehouseId): array
    {
        $productTriggers = ProductStock::with('product', 'warehouse')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->get()
            ->map(fn (ProductStock $stock) => [
                'stream' => 'product',
                'item_id' => $stock->product_id,
                'item_name' => $stock->product?->name ?? 'Unknown',
                'item_sku' => $stock->product?->sku ?? '—',
                'warehouse' => $stock->warehouse?->name ?? 'Default',
                'available' => max(0, $stock->current_stock - $stock->reserved_stock),
                'reorder_point' => $stock->reorder_point,
                'suggested_reorder_qty' => max($stock->reorder_point * 3 - $stock->current_stock, 0),
            ]);

        $supplyTriggers = SupplyStock::with('supply', 'warehouse')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereHas('supply', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->get()
            ->map(fn (SupplyStock $stock) => [
                'stream' => 'supply',
                'item_id' => $stock->supply_id,
                'item_name' => $stock->supply?->name ?? 'Unknown',
                'item_sku' => $stock->supply?->sku ?? '—',
                'warehouse' => $stock->warehouse?->name ?? 'Default',
                'available' => max(0, $stock->current_stock - $stock->reserved_stock),
                'reorder_point' => $stock->reorder_point,
                'suggested_reorder_qty' => max($stock->reorder_point * 3 - $stock->current_stock, 0),
            ]);

        return $productTriggers->merge($supplyTriggers)
            ->sortBy('available')
            ->values()
            ->all();
    }

    private function productStockValue(?int $warehouseId): float
    {
        return (float) DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('p.is_active', true)
            ->when($warehouseId, fn ($q) => $q->where('ps.warehouse_id', $warehouseId))
            ->sum(DB::raw('ps.current_stock * COALESCE(p.cost_price, 0)'));
    }

    private function supplyStockValue(?int $warehouseId): float
    {
        return (float) DB::table('supply_stocks as ss')
            ->join('supplies as s', 's.id', '=', 'ss.supply_id')
            ->where('s.is_active', true)
            ->whereNull('s.deleted_at')
            ->when($warehouseId, fn ($q) => $q->where('ss.warehouse_id', $warehouseId))
            ->sum(DB::raw('ss.current_stock * COALESCE(s.cost_price, 0)'));
    }

    private function countLowStock($query, ?int $warehouseId, string $type): int
    {
        $base = $type === 'product'
            ? ProductStock::whereHas('product', fn ($q) => $q->where('is_active', true))
            : SupplyStock::whereHas('supply', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'));

        return $base
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->whereRaw('(current_stock - reserved_stock) > 0')
            ->count();
    }

    private function countOutOfStock($query, ?int $warehouseId, string $type): int
    {
        $base = $type === 'product'
            ? ProductStock::whereHas('product', fn ($q) => $q->where('is_active', true))
            : SupplyStock::whereHas('supply', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'));

        return $base
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereRaw('(current_stock - reserved_stock) <= 0')
            ->count();
    }

    private function ensureAlert($stock, string $morphType): bool
    {
        $available = max(0, $stock->current_stock - $stock->reserved_stock);
        $reorderPoint = (int) $stock->reorder_point;

        $alertType = match (true) {
            $available <= 0 => StockAlert::TYPE_OUT_OF_STOCK,
            $reorderPoint > 0 && $available <= $reorderPoint => StockAlert::TYPE_LOW_STOCK,
            default => null,
        };

        if (! $alertType) {
            return false;
        }

        $suggestedQty = max($reorderPoint * 3 - $stock->current_stock, 0);

        $existing = StockAlert::where('stockable_type', $morphType)
            ->where('stockable_id', $stock->id)
            ->where('alert_type', $alertType)
            ->whereIn('status', [StockAlert::STATUS_OPEN, StockAlert::STATUS_ACKNOWLEDGED])
            ->first();

        if ($existing) {
            $existing->update([
                'current_stock' => $stock->current_stock,
                'reserved_stock' => $stock->reserved_stock,
                'reorder_point' => $reorderPoint,
                'suggested_reorder_qty' => $suggestedQty,
                'warehouse_id' => $stock->warehouse_id,
            ]);

            return false;
        }

        StockAlert::create([
            'stockable_type' => $morphType,
            'stockable_id' => $stock->id,
            'warehouse_id' => $stock->warehouse_id,
            'alert_type' => $alertType,
            'current_stock' => $stock->current_stock,
            'reserved_stock' => $stock->reserved_stock,
            'reorder_point' => $reorderPoint,
            'suggested_reorder_qty' => $suggestedQty,
            'status' => StockAlert::STATUS_OPEN,
        ]);

        return true;
    }

    private function alertStillValid(StockAlert $alert): bool
    {
        $stock = $alert->stockable;
        if (! $stock) {
            return false;
        }

        $available = max(0, $stock->current_stock - $stock->reserved_stock);

        return match ($alert->alert_type) {
            StockAlert::TYPE_OUT_OF_STOCK => $available <= 0,
            StockAlert::TYPE_LOW_STOCK => $stock->reorder_point > 0 && $available <= $stock->reorder_point,
            default => true,
        };
    }
}
