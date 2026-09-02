<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\SupplyStock;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Products\Models\ProductStock;

class WarehouseMapService
{
    public function getWarehouseMap(int $warehouseId): array
    {
        $warehouse = Warehouse::with(['locations' => fn ($q) => $q->orderBy('row_index')->orderBy('col_index')])
            ->findOrFail($warehouseId);

        $locations = $warehouse->locations;

        $productStockByLoc = ProductStock::where('warehouse_id', $warehouseId)
            ->whereNotNull('location_id')
            ->select('location_id', DB::raw('SUM(current_stock) as total'), DB::raw('SUM(reserved_stock) as reserved'))
            ->groupBy('location_id')->get()->keyBy('location_id');

        $supplyStockByLoc = SupplyStock::where('warehouse_id', $warehouseId)
            ->whereNotNull('location_id')
            ->select('location_id', DB::raw('SUM(current_stock) as total'))
            ->groupBy('location_id')->pluck('total', 'location_id');

        $productSkuCount = ProductStock::where('warehouse_id', $warehouseId)
            ->whereNotNull('location_id')->where('current_stock', '>', 0)
            ->select('location_id', DB::raw('COUNT(DISTINCT product_id) as c'))
            ->groupBy('location_id')->pluck('c', 'location_id');

        $supplySkuCount = SupplyStock::where('warehouse_id', $warehouseId)
            ->whereNotNull('location_id')->where('current_stock', '>', 0)
            ->select('location_id', DB::raw('COUNT(DISTINCT supply_id) as c'))
            ->groupBy('location_id')->pluck('c', 'location_id');

        $gridRows = 0;
        $gridCols = 0;
        $grid = [];

        foreach ($locations as $loc) {
            $row = $loc->row_index ?? 0;
            $col = $loc->col_index ?? 0;
            $gridRows = max($gridRows, $row);
            $gridCols = max($gridCols, $col);

            $pStock = (int) ($productStockByLoc[$loc->id]->total ?? 0);
            $pReserved = (int) ($productStockByLoc[$loc->id]->reserved ?? 0);
            $sStock = (int) ($supplyStockByLoc[$loc->id] ?? 0);
            $total = $pStock + $sStock;
            $capacity = $loc->capacity ?? 0;
            $occPct = $capacity > 0 ? min(100, (int) round($total / $capacity * 100)) : ($total > 0 ? 100 : 0);

            $grid[] = [
                'id' => $loc->id,
                'code' => $loc->code,
                'name' => $loc->name,
                'type' => $loc->type,
                'row_index' => $row,
                'col_index' => $col,
                'zone_color' => $loc->zone_color,
                'capacity' => $capacity,
                'is_active' => $loc->is_active,
                'product_stock' => $pStock,
                'supply_stock' => $sStock,
                'reserved_stock' => $pReserved,
                'total_stock' => $total,
                'available_stock' => max(0, $total - $pReserved),
                'occupancy_pct' => $occPct,
                'sku_count' => (int) ($productSkuCount[$loc->id] ?? 0) + (int) ($supplySkuCount[$loc->id] ?? 0),
                'status' => $this->computeStatus($total, $capacity, $loc->is_active),
            ];
        }

        usort($grid, fn ($a, $b) => [$a['row_index'], $a['col_index']] <=> [$b['row_index'], $b['col_index']]);

        return [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'address' => $warehouse->address,
                'is_active' => $warehouse->is_active,
            ],
            'grid' => $grid,
            'grid_dimensions' => ['rows' => $gridRows + 1, 'cols' => $gridCols + 1],
            'summary' => $this->computeSummary($grid),
        ];
    }

    public function getAllWarehousesOverview(): array
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        $overview = [];

        foreach ($warehouses as $wh) {
            $pTotal = (int) ProductStock::where('warehouse_id', $wh->id)->sum('current_stock');
            $sTotal = (int) SupplyStock::where('warehouse_id', $wh->id)->sum('current_stock');
            $locCount = (int) WarehouseLocation::where('warehouse_id', $wh->id)->where('is_active', true)->count();

            $occupiedLocs = WarehouseLocation::where('warehouse_id', $wh->id)->where('is_active', true)
                ->where(function ($q): void {
                    $q->whereHas('productStocks', fn ($sq) => $sq->where('current_stock', '>', 0))
                        ->orWhereHas('supplyStocks', fn ($sq) => $sq->where('current_stock', '>', 0));
                })->count();

            $overview[] = [
                'id' => $wh->id,
                'name' => $wh->name,
                'code' => $wh->code,
                'is_default' => $wh->is_default,
                'total_locations' => $locCount,
                'occupied_locations' => $occupiedLocs,
                'total_stock' => $pTotal + $sTotal,
                'occupancy_pct' => $locCount > 0 ? (int) round($occupiedLocs / $locCount * 100) : 0,
            ];
        }

        return $overview;
    }

    public function updateLocationCoordinates(int $locationId, int $row, int $col, ?string $zoneColor = null): WarehouseLocation
    {
        $loc = WarehouseLocation::findOrFail($locationId);
        $loc->row_index = $row;
        $loc->col_index = $col;
        if ($zoneColor !== null) {
            $loc->zone_color = $zoneColor;
        }
        $loc->save();

        return $loc->fresh();
    }

    public function getLocationDetails(int $locationId): array
    {
        $loc = WarehouseLocation::with('warehouse')->findOrFail($locationId);

        $productStocks = ProductStock::where('location_id', $locationId)
            ->where('current_stock', '>', 0)
            ->with(['product:id,sku,name', 'variant:id,sku,variant_name'])
            ->orderBy('current_stock', 'desc')
            ->limit(50)
            ->get();

        $supplyStocks = SupplyStock::where('location_id', $locationId)
            ->where('current_stock', '>', 0)
            ->with(['supply:id,sku,name'])
            ->orderBy('current_stock', 'desc')
            ->limit(50)
            ->get();

        return [
            'location' => [
                'id' => $loc->id,
                'code' => $loc->code,
                'name' => $loc->name,
                'type' => $loc->type,
                'capacity' => $loc->capacity,
                'is_active' => $loc->is_active,
                'row_index' => $loc->row_index,
                'col_index' => $loc->col_index,
                'zone_color' => $loc->zone_color,
                'warehouse' => [
                    'id' => $loc->warehouse->id,
                    'name' => $loc->warehouse->name,
                    'code' => $loc->warehouse->code,
                ],
            ],
            'product_stocks' => $productStocks->map(fn ($s) => [
                'id' => $s->id,
                'sku' => $s->product?->sku,
                'name' => $s->product?->name,
                'variant_sku' => $s->variant?->sku,
                'variant_name' => $s->variant?->variant_name,
                'current_stock' => $s->current_stock,
                'reserved_stock' => $s->reserved_stock,
                'available_stock' => $s->available_stock,
            ]),
            'supply_stocks' => $supplyStocks->map(fn ($s) => [
                'id' => $s->id,
                'sku' => $s->supply?->sku,
                'name' => $s->supply?->name,
                'current_stock' => $s->current_stock,
                'reserved_stock' => $s->reserved_stock,
                'available_stock' => $s->available_stock,
            ]),
        ];
    }

    private function computeStatus(int $totalStock, int $capacity, bool $isActive): string
    {
        if (! $isActive) {
            return 'INACTIVE';
        }
        if ($totalStock === 0) {
            return 'EMPTY';
        }
        if ($capacity > 0) {
            $pct = ($totalStock / $capacity) * 100;
            if ($pct >= 90) {
                return 'FULL';
            }
            if ($pct >= 70) {
                return 'HIGH';
            }
            if ($pct <= 20) {
                return 'LOW';
            }
        }

        return 'MEDIUM';
    }

    private function computeSummary(array $grid): array
    {
        $total = count($grid);
        $empty = count(array_filter($grid, fn ($c) => $c['status'] === 'EMPTY'));
        $low = count(array_filter($grid, fn ($c) => $c['status'] === 'LOW'));
        $medium = count(array_filter($grid, fn ($c) => $c['status'] === 'MEDIUM'));
        $high = count(array_filter($grid, fn ($c) => $c['status'] === 'HIGH'));
        $full = count(array_filter($grid, fn ($c) => $c['status'] === 'FULL'));
        $inactive = count(array_filter($grid, fn ($c) => $c['status'] === 'INACTIVE'));
        $totalStock = array_sum(array_column($grid, 'total_stock'));
        $totalReserved = array_sum(array_column($grid, 'reserved_stock'));
        $totalCapacity = array_sum(array_column($grid, 'capacity'));
        $totalSkus = array_sum(array_column($grid, 'sku_count'));

        return [
            'total_locations' => $total,
            'empty' => $empty,
            'low' => $low,
            'medium' => $medium,
            'high' => $high,
            'full' => $full,
            'inactive' => $inactive,
            'total_stock' => $totalStock,
            'total_reserved' => $totalReserved,
            'total_available' => max(0, $totalStock - $totalReserved),
            'total_capacity' => $totalCapacity,
            'total_skus' => $totalSkus,
            'overall_occupancy' => $totalCapacity > 0 ? (int) round($totalStock / $totalCapacity * 100) : 0,
        ];
    }
}
