<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockCostLot;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Support\Collection;

class InventoryValuationService
{
    /**
     * Get full valuation report.
     *
     * @param  array<string, mixed>  $filters  method, warehouse_id, stream, search
     * @return array<string, mixed>
     */
    public function getValuation(array $filters = []): array
    {
        $method = $filters['method'] ?? 'FIFO';
        $warehouseId = $filters['warehouse_id'] ?? null;
        $stream = $filters['stream'] ?? 'all';
        $search = $filters['search'] ?? null;
        $perPage = min(200, max(10, (int) ($filters['per_page'] ?? 50)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $items = $this->collectValuationItems($method, $warehouseId, $stream, $search);
        $total = $items->count();
        $offset = ($page - 1) * $perPage;
        $paged = $items->slice($offset, $perPage)->values();

        return [
            'summary' => $this->getSummary($method, $warehouseId),
            'by_warehouse' => $this->getByWarehouse($method),
            'by_category' => $this->getByCategory($method, $warehouseId),
            'items' => $paged,
            'method' => $method,
            'pagination' => [
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? $offset + 1 : null,
                'to' => min($offset + $perPage, $total),
            ],
            'filters' => [
                'method' => $method,
                'warehouse_id' => $warehouseId,
                'stream' => $stream,
                'search' => $search,
            ],
        ];
    }

    /**
     * Collect valuation items for products and supplies.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectValuationItems(string $method, ?int $warehouseId, string $stream, ?string $search): Collection
    {
        $items = collect();

        if ($stream === 'all' || $stream === 'product') {
            $items = $items->merge($this->collectProductValuation($method, $warehouseId, $search));
        }

        if ($stream === 'all' || $stream === 'supply') {
            $items = $items->merge($this->collectSupplyValuation($method, $warehouseId, $search));
        }

        return $items->sortByDesc('total_value')->values();
    }

    /**
     * Collect product valuation items.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectProductValuation(string $method, ?int $warehouseId, ?string $search): Collection
    {
        $query = ProductStock::with(['product', 'warehouse'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search, function ($q) use ($search) {
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
            })
            ->get();

        return $query->map(function (ProductStock $stock) use ($method) {
            $product = $stock->product;
            $currentStock = (int) $stock->current_stock;
            $reservedStock = (int) $stock->reserved_stock;
            $availableStock = max(0, $currentStock - $reservedStock);

            $unitCost = $this->calculateProductUnitCost($method, $stock);
            $totalValue = round($currentStock * $unitCost, 2);
            $availableValue = round($availableStock * $unitCost, 2);
            $sellingPrice = (float) ($product->selling_price ?? 0);
            $potentialValue = round($currentStock * $sellingPrice, 2);
            $margin = $sellingPrice > 0 ? round(($sellingPrice - $unitCost) / $sellingPrice * 100, 1) : 0;

            return [
                'stock_id' => $stock->id,
                'stream' => 'product',
                'item_id' => $product->id,
                'item_name' => $product->name,
                'item_sku' => $product->sku,
                'category' => $product->category ?? '—',
                'warehouse' => $stock->warehouse?->name ?? 'Default',
                'warehouse_id' => $stock->warehouse_id,
                'current_stock' => $currentStock,
                'reserved_stock' => $reservedStock,
                'available_stock' => $availableStock,
                'unit_cost' => round($unitCost, 4),
                'total_value' => $totalValue,
                'available_value' => $availableValue,
                'selling_price' => $sellingPrice,
                'potential_value' => $potentialValue,
                'margin_pct' => $margin,
                'method' => $method,
            ];
        })->toBase()->filter(fn ($item) => $item['current_stock'] > 0);
    }

    /**
     * Collect supply valuation items.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectSupplyValuation(string $method, ?int $warehouseId, ?string $search): Collection
    {
        $query = SupplyStock::with(['supply', 'warehouse'])
            ->whereHas('supply', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search, function ($q) use ($search) {
                $q->whereHas('supply', fn ($sq) => $sq->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
            })
            ->get();

        return $query->map(function (SupplyStock $stock) {
            $supply = $stock->supply;
            $currentStock = (int) $stock->current_stock;
            $reservedStock = (int) $stock->reserved_stock;
            $availableStock = max(0, $currentStock - $reservedStock);

            $unitCost = (float) ($supply->cost_price ?? 0);
            $totalValue = round($currentStock * $unitCost, 2);
            $availableValue = round($availableStock * $unitCost, 2);

            return [
                'stock_id' => $stock->id,
                'stream' => 'supply',
                'item_id' => $supply->id,
                'item_name' => $supply->name,
                'item_sku' => $supply->sku,
                'category' => $supply->category ?? $supply->stock_category ?? '—',
                'warehouse' => $stock->warehouse?->name ?? 'Default',
                'warehouse_id' => $stock->warehouse_id,
                'current_stock' => $currentStock,
                'reserved_stock' => $reservedStock,
                'available_stock' => $availableStock,
                'unit_cost' => round($unitCost, 4),
                'total_value' => $totalValue,
                'available_value' => $availableValue,
                'selling_price' => 0,
                'potential_value' => 0,
                'margin_pct' => 0,
                'method' => 'COST',
            ];
        })->toBase()->filter(fn ($item) => $item['current_stock'] > 0);
    }

    /**
     * Calculate unit cost for a product stock using the specified method.
     *
     * FIFO: Use oldest cost lots first.
     * LIFO: Use newest cost lots first.
     * Weighted Average: Average cost across all remaining lots.
     */
    private function calculateProductUnitCost(string $method, ProductStock $stock): float
    {
        $lots = StockCostLot::where('product_id', $stock->product_id)
            ->when($stock->variant_id, fn ($q) => $q->where('variant_id', $stock->variant_id))
            ->when(! $stock->variant_id, fn ($q) => $q->whereNull('variant_id'))
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at', $method === 'LIFO' ? 'desc' : 'asc')
            ->orderBy('id', $method === 'LIFO' ? 'desc' : 'asc')
            ->get();

        if ($lots->isEmpty()) {
            // Fall back to product cost_price
            $product = $stock->product;
            if ($stock->variant_id) {
                $variant = $product->variants()->find($stock->variant_id);

                return (float) ($variant?->cost_price ?? $product->cost_price ?? 0);
            }

            return (float) ($product->cost_price ?? 0);
        }

        if ($method === 'WEIGHTED_AVERAGE') {
            $totalQty = (float) $lots->sum('quantity_remaining');
            if ($totalQty <= 0) {
                return 0;
            }

            $totalCost = $lots->sum(fn ($lot) => (float) $lot->quantity_remaining * (float) $lot->unit_cost * (float) $lot->exchange_rate);

            return round($totalCost / $totalQty, 4);
        }

        // FIFO / LIFO — weighted cost of remaining lots
        $totalQty = (float) $lots->sum('quantity_remaining');
        if ($totalQty <= 0) {
            return 0;
        }

        $totalCost = $lots->sum(fn ($lot) => (float) $lot->quantity_remaining * (float) $lot->unit_cost * (float) $lot->exchange_rate);

        return round($totalCost / $totalQty, 4);
    }

    /**
     * Get summary totals.
     *
     * @return array<string, mixed>
     */
    private function getSummary(string $method, ?int $warehouseId): array
    {
        $productItems = $this->collectProductValuation($method, $warehouseId, null);
        $supplyItems = $this->collectSupplyValuation($method, $warehouseId, null);

        $productValue = $productItems->sum('total_value');
        $supplyValue = $supplyItems->sum('total_value');
        $productPotential = $productItems->sum('potential_value');
        $productUnits = $productItems->sum('current_stock');
        $supplyUnits = $supplyItems->sum('current_stock');

        $allItems = $productItems->merge($supplyItems);
        $totalValue = $allItems->sum('total_value');
        $availableValue = $allItems->sum('available_value');
        $reservedValue = $totalValue - $availableValue;

        return [
            'method' => $method,
            'total_value' => round($totalValue, 2),
            'product_value' => round($productValue, 2),
            'supply_value' => round($supplyValue, 2),
            'available_value' => round($availableValue, 2),
            'reserved_value' => round($reservedValue, 2),
            'potential_sales_value' => round($productPotential, 2),
            'potential_margin' => round($productPotential - $productValue, 2),
            'product_units' => (int) $productUnits,
            'supply_units' => (int) $supplyUnits,
            'total_skus' => $allItems->count(),
            'product_skus' => $productItems->count(),
            'supply_skus' => $supplyItems->count(),
        ];
    }

    /**
     * Get valuation breakdown by warehouse.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getByWarehouse(string $method): array
    {
        return Warehouse::where('is_active', true)
            ->get()
            ->map(function (Warehouse $warehouse) use ($method) {
                $productItems = $this->collectProductValuation($method, $warehouse->id, null);
                $supplyItems = $this->collectSupplyValuation($method, $warehouse->id, null);

                $productValue = $productItems->sum('total_value');
                $supplyValue = $supplyItems->sum('total_value');

                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'code' => $warehouse->code,
                    'product_value' => round($productValue, 2),
                    'supply_value' => round($supplyValue, 2),
                    'total_value' => round($productValue + $supplyValue, 2),
                    'sku_count' => $productItems->count() + $supplyItems->count(),
                ];
            })
            ->filter(fn ($w) => $w['total_value'] > 0)
            ->sortByDesc('total_value')
            ->values()
            ->all();
    }

    /**
     * Get valuation breakdown by category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getByCategory(string $method, ?int $warehouseId): array
    {
        $items = $this->collectValuationItems($method, $warehouseId, 'all', null);

        return $items->groupBy('category')
            ->map(function ($group, $category) {
                return [
                    'category' => $category,
                    'total_value' => round($group->sum('total_value'), 2),
                    'sku_count' => $group->count(),
                    'units' => (int) $group->sum('current_stock'),
                ];
            })
            ->sortByDesc('total_value')
            ->values()
            ->all();
    }

    /**
     * Generate CSV export of valuation data.
     */
    public function exportCsv(array $filters = []): string
    {
        $method = $filters['method'] ?? 'FIFO';
        $warehouseId = $filters['warehouse_id'] ?? null;
        $stream = $filters['stream'] ?? 'all';
        $search = $filters['search'] ?? null;

        $items = $this->collectValuationItems($method, $warehouseId, $stream, $search);
        $summary = $this->getSummary($method, $warehouseId);

        $lines = [];

        // Summary section
        $lines[] = 'INVENTORY VALUATION REPORT';
        $lines[] = 'Method,'.$method;
        $lines[] = 'Generated,'.now()->toDateTimeString();
        $lines[] = '';
        $lines[] = 'SUMMARY';
        $lines[] = 'Metric,Value';
        $lines[] = 'Total Inventory Value,'.number_format($summary['total_value'], 2, '.', '');
        $lines[] = 'Product Value,'.number_format($summary['product_value'], 2, '.', '');
        $lines[] = 'Supply Value,'.number_format($summary['supply_value'], 2, '.', '');
        $lines[] = 'Available Value,'.number_format($summary['available_value'], 2, '.', '');
        $lines[] = 'Reserved Value,'.number_format($summary['reserved_value'], 2, '.', '');
        $lines[] = 'Potential Sales Value,'.number_format($summary['potential_sales_value'], 2, '.', '');
        $lines[] = 'Potential Margin,'.number_format($summary['potential_margin'], 2, '.', '');
        $lines[] = 'Total SKUs,'.$summary['total_skus'];
        $lines[] = 'Product SKUs,'.$summary['product_skus'];
        $lines[] = 'Supply SKUs,'.$summary['supply_skus'];
        $lines[] = 'Product Units,'.$summary['product_units'];
        $lines[] = 'Supply Units,'.$summary['supply_units'];
        $lines[] = '';

        // By warehouse
        $lines[] = 'BY WAREHOUSE';
        $lines[] = 'Warehouse,Code,Product Value,Supply Value,Total Value,SKU Count';
        foreach ($this->getByWarehouse($method) as $w) {
            $lines[] = implode(',', [
                $this->csvEscape($w['name']),
                $w['code'],
                number_format($w['product_value'], 2, '.', ''),
                number_format($w['supply_value'], 2, '.', ''),
                number_format($w['total_value'], 2, '.', ''),
                $w['sku_count'],
            ]);
        }
        $lines[] = '';

        // Item-level detail
        $lines[] = 'ITEM DETAIL';
        $lines[] = 'Stream,Item Name,SKU,Category,Warehouse,Current Stock,Reserved Stock,Available Stock,Unit Cost,Total Value,Available Value,Selling Price,Potential Value,Margin %,Method';
        foreach ($items as $item) {
            $lines[] = implode(',', [
                $item['stream'],
                $this->csvEscape($item['item_name']),
                $this->csvEscape($item['item_sku']),
                $this->csvEscape($item['category']),
                $this->csvEscape($item['warehouse']),
                $item['current_stock'],
                $item['reserved_stock'],
                $item['available_stock'],
                number_format($item['unit_cost'], 4, '.', ''),
                number_format($item['total_value'], 2, '.', ''),
                number_format($item['available_value'], 2, '.', ''),
                number_format($item['selling_price'], 2, '.', ''),
                number_format($item['potential_value'], 2, '.', ''),
                $item['margin_pct'],
                $item['method'],
            ]);
        }

        return implode("\n", $lines);
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
