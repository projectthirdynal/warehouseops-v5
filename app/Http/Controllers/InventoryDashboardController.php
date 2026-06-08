<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockCostLot;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryDashboardController extends Controller
{
    public function index()
    {
        $since30 = now()->subDays(30)->startOfDay();

        // ── Stock value ──────────────────────────────────────────────
        $stockValue = (float) DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->sum(DB::raw('ps.current_stock * COALESCE(p.cost_price, 0)'));

        // ── Low stock counts ─────────────────────────────────────────
        $lowStockCount = ProductStock::whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->count();

        $supplyLowStockCount = SupplyStock::whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->count();

        // ── Expiring lots (within 30 days) ───────────────────────────
        $expiringSoon = StockCostLot::whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->whereDate('expiry_date', '>=', now())
            ->where('quantity_remaining', '>', 0)
            ->count();

        // ── Stats ────────────────────────────────────────────────────
        $stats = [
            'total_products'     => Product::where('is_active', true)->count(),
            'total_supplies'     => Supply::where('is_active', true)->count(),
            'total_warehouses'   => Warehouse::where('is_active', true)->count(),
            'stock_value'        => $stockValue,
            'low_stock_count'    => $lowStockCount,
            'supply_low_stock'   => $supplyLowStockCount,
            'expiring_soon'      => $expiringSoon,
            'pending_adjustments' => StockAdjustment::where('status', 'PENDING')->count(),
            'pending_prs'        => PurchaseRequest::where('status', PrStatus::SUBMITTED)->count(),
            'open_pos'           => PurchaseOrder::whereIn('status', [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED])->count(),
        ];

        // ── Recent product movements ─────────────────────────────────
        $recentMovements = InventoryMovement::with(['product:id,sku,name', 'warehouse:id,name'])
            ->latest()
            ->limit(20)
            ->get(['id', 'product_id', 'warehouse_id', 'type', 'quantity', 'created_at', 'notes']);

        // ── Recent supply movements ──────────────────────────────────
        $recentSupplyMovements = DB::table('supply_movements as sm')
            ->leftJoin('supplies as s', 's.id', '=', 'sm.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->select([
                'sm.id', 'sm.type', 'sm.quantity', 'sm.notes', 'sm.created_at',
                's.id as supply_id', 's.sku as supply_sku', 's.name as supply_name',
                'w.id as warehouse_id', 'w.name as warehouse_name',
            ])
            ->latest('sm.created_at')
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'id'        => $m->id,
                'type'      => $m->type,
                'quantity'  => (int) $m->quantity,
                'notes'     => $m->notes,
                'created_at' => $m->created_at,
                'supply'    => $m->supply_id ? ['id' => $m->supply_id, 'sku' => $m->supply_sku, 'name' => $m->supply_name] : null,
                'warehouse' => $m->warehouse_id ? ['id' => $m->warehouse_id, 'name' => $m->warehouse_name] : null,
            ]);

        // ── Low stock products (detail) ──────────────────────────────
        $lowStock = ProductStock::with(['product:id,sku,name', 'warehouse:id,name'])
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->orderByRaw('(current_stock - reserved_stock) ASC')
            ->limit(10)
            ->get();

        // ── Low stock materials (detail) ─────────────────────────────
        $supplyLowStock = DB::table('supply_stocks as ss')
            ->join('supplies as s', 's.id', '=', 'ss.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ss.warehouse_id')
            ->where('ss.reorder_point', '>', 0)
            ->whereRaw('(ss.current_stock - ss.reserved_stock) <= ss.reorder_point')
            ->orderByRaw('(ss.current_stock - ss.reserved_stock) ASC')
            ->limit(10)
            ->select([
                'ss.id', 's.name as supply_name', 's.sku',
                'w.name as warehouse_name',
                'ss.current_stock', 'ss.reserved_stock', 'ss.reorder_point',
                DB::raw('CASE WHEN ss.current_stock - ss.reserved_stock > 0 THEN ss.current_stock - ss.reserved_stock ELSE 0 END as available_stock'),
            ])
            ->get();

        // ── Expiring lots (detail) ───────────────────────────────────
        $expiringLots = DB::table('stock_cost_lots as scl')
            ->join('products as p', 'p.id', '=', 'scl.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'scl.warehouse_id')
            ->whereNotNull('scl.expiry_date')
            ->whereDate('scl.expiry_date', '<=', now()->addDays(30))
            ->whereDate('scl.expiry_date', '>=', now())
            ->where('scl.quantity_remaining', '>', 0)
            ->orderBy('scl.expiry_date')
            ->limit(10)
            ->select([
                'scl.id', 'p.name as product_name', 'p.sku',
                'w.name as warehouse_name',
                'scl.quantity_remaining', 'scl.expiry_date', 'scl.batch_number',
            ])
            ->get();

        // ── 30-day product movement trend ────────────────────────────
        $movementTrend = DB::table('inventory_movements')
            ->where('created_at', '>=', $since30)
            ->selectRaw("DATE(created_at) as date,
                SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as stock_in,
                SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as stock_out")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        // ── 30-day supply movement trend ─────────────────────────────
        $supplyMovementTrend = DB::table('supply_movements')
            ->where('created_at', '>=', $since30)
            ->selectRaw("DATE(created_at) as date,
                SUM(CASE WHEN type = 'STOCK_IN' AND quantity > 0 THEN quantity ELSE 0 END) as stock_in,
                SUM(CASE WHEN type = 'STOCK_OUT' THEN ABS(quantity) ELSE 0 END) as stock_out,
                SUM(CASE WHEN type = 'ADJUSTMENT' THEN ABS(quantity) ELSE 0 END) as adjustments")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        // ── Warehouse stock summary ──────────────────────────────────
        $warehouseStockSummary = DB::table('warehouses as w')
            ->leftJoin('product_stocks as ps', 'ps.warehouse_id', '=', 'w.id')
            ->leftJoin('products as p', 'p.id', '=', 'ps.product_id')
            ->where('w.is_active', true)
            ->groupBy('w.id', 'w.name', 'w.code')
            ->select([
                'w.id', 'w.name', 'w.code',
                DB::raw('COALESCE(COUNT(DISTINCT ps.product_id), 0) as product_units'),
                DB::raw('COALESCE(SUM(ps.current_stock * COALESCE(p.cost_price, 0)), 0) as stock_value'),
            ])
            ->get();

        return Inertia::render('Inventory/Dashboard', [
            'stats'                   => $stats,
            'recent_movements'        => $recentMovements,
            'recent_supply_movements' => $recentSupplyMovements,
            'low_stock'               => $lowStock,
            'supply_low_stock'        => $supplyLowStock,
            'expiring_lots'           => $expiringLots,
            'movement_trend'          => $movementTrend,
            'supply_movement_trend'   => $supplyMovementTrend,
            'warehouse_stock_summary' => $warehouseStockSummary,
        ]);
    }

    public function movements(\Illuminate\Http\Request $request)
    {
        $type = $request->input('type');
        if ($type === 'all') {
            $type = null;
        }

        $movements = InventoryMovement::with(['product:id,sku,name', 'warehouse:id,name', 'location:id,code', 'performer:id,name'])
            ->when($type,                  fn ($q, $v) => $q->where('type', $v))
            ->when($request->product_id,   fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->from,         fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($request->to,           fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($request->stock === 'low', fn ($q) => $q->whereHas('product', function ($q2) {
                $q2->whereHas('stocks', function ($q3) {
                    $q3->whereRaw('(current_stock - reserved_stock) <= reorder_point')
                       ->where('reorder_point', '>', 0);
                });
            }))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'filters'   => $request->only(['type', 'product_id', 'warehouse_id', 'from', 'to', 'stock']),
        ]);
    }
}
