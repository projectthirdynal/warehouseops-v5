<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryDashboardController extends Controller
{
    public function index()
    {
        $stockValue = (float) DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->sum(DB::raw('ps.current_stock * COALESCE(p.cost_price, 0)'));

        $lowStockCount = ProductStock::whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->count();

        $supplyLowStockCount = DB::table('supply_stocks')
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->count();

        $expiringCount = DB::table('stock_cost_lots')
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays(30)->toDateString())
            ->count();

        $pendingAdjustments = DB::table('stock_adjustments')->where('status', 'PENDING')->count();

        $stats = [
            'total_products'        => Product::where('is_active', true)->count(),
            'total_supplies'        => Supply::where('is_active', true)->count(),
            'total_warehouses'      => Warehouse::where('is_active', true)->count(),
            'stock_value'           => $stockValue,
            'low_stock_count'       => $lowStockCount,
            'supply_low_stock'      => $supplyLowStockCount,
            'expiring_soon'         => $expiringCount,
            'pending_adjustments'   => $pendingAdjustments,
            'pending_prs'           => PurchaseRequest::where('status', PrStatus::SUBMITTED)->count(),
            'open_pos'              => PurchaseOrder::whereIn('status', [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED])->count(),
        ];

        $recentMovements = InventoryMovement::with(['product:id,sku,name', 'warehouse:id,name'])
            ->latest()
            ->limit(20)
            ->get(['id', 'product_id', 'warehouse_id', 'type', 'quantity', 'created_at', 'notes']);

        $lowStock = ProductStock::with(['product:id,sku,name', 'warehouse:id,name'])
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->orderByRaw('(current_stock - reserved_stock) ASC')
            ->limit(10)
            ->get();

        $supplyLowStock = DB::table('supply_stocks as ss')
            ->join('supplies as s', 's.id', '=', 'ss.supply_id')
            ->join('warehouses as w', 'w.id', '=', 'ss.warehouse_id')
            ->whereRaw('(ss.current_stock - ss.reserved_stock) <= ss.reorder_point')
            ->where('ss.reorder_point', '>', 0)
            ->select(['ss.id', 's.name as supply_name', 's.sku', 'w.name as warehouse_name',
                'ss.current_stock', 'ss.reserved_stock', 'ss.reorder_point',
                DB::raw('(ss.current_stock - ss.reserved_stock) as available_stock')])
            ->orderByRaw('(ss.current_stock - ss.reserved_stock) ASC')
            ->limit(10)
            ->get();

        $expiringLots = DB::table('stock_cost_lots as cl')
            ->join('products as p', 'p.id', '=', 'cl.product_id')
            ->join('warehouses as w', 'w.id', '=', 'cl.warehouse_id')
            ->where('cl.quantity_remaining', '>', 0)
            ->whereNotNull('cl.expiry_date')
            ->where('cl.expiry_date', '<=', now()->addDays(30)->toDateString())
            ->select(['cl.id', 'p.name as product_name', 'p.sku', 'w.name as warehouse_name',
                'cl.quantity_remaining', 'cl.expiry_date', 'cl.batch_number'])
            ->orderBy('cl.expiry_date')
            ->limit(10)
            ->get();

        $warehouseStockSummary = DB::table('warehouses as w')
            ->leftJoin('product_stocks as ps', 'ps.warehouse_id', '=', 'w.id')
            ->leftJoin('products as p', 'p.id', '=', 'ps.product_id')
            ->where('w.is_active', true)
            ->select([
                'w.id', 'w.name', 'w.code',
                DB::raw('COALESCE(SUM(ps.current_stock), 0) as product_units'),
                DB::raw('COALESCE(SUM(ps.current_stock * COALESCE(p.cost_price,0)), 0) as stock_value'),
            ])
            ->groupBy('w.id', 'w.name', 'w.code')
            ->orderBy('w.name')
            ->get();

        $movementTrend = DB::table('inventory_movements')
            ->select([
                DB::raw("DATE(created_at) as date"),
                DB::raw("SUM(CASE WHEN type = 'STOCK_IN' THEN quantity ELSE 0 END) as stock_in"),
                DB::raw("SUM(CASE WHEN type = 'STOCK_OUT' THEN ABS(quantity) ELSE 0 END) as stock_out"),
            ])
            ->where('created_at', '>=', now()->subDays(30))
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get();

        return Inertia::render('Inventory/Dashboard', [
            'stats'                  => $stats,
            'recent_movements'       => $recentMovements,
            'low_stock'              => $lowStock,
            'supply_low_stock'       => $supplyLowStock,
            'expiring_lots'          => $expiringLots,
            'warehouse_stock_summary' => $warehouseStockSummary,
            'movement_trend'         => $movementTrend,
        ]);
    }

    public function movements(\Illuminate\Http\Request $request)
    {
        $movements = InventoryMovement::with(['product:id,sku,name', 'warehouse:id,name', 'location:id,code', 'performer:id,name'])
            ->when($request->type,         fn ($q, $v) => $q->where('type', $v))
            ->when($request->product_id,   fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->from,         fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->to,           fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'filters'   => $request->only(['type', 'product_id', 'warehouse_id', 'from', 'to']),
        ]);
    }
}
